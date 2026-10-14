#!/usr/bin/env bash
set -uo pipefail

CONF="${ANTIDDOS_CONF:-/etc/antiddos/agent.conf}"
WHITELIST_FILE="${ANTIDDOS_WHITELIST:-/etc/antiddos/whitelist.txt}"
SYSCTL_FILE="/etc/sysctl.d/98-antiddos-shield.conf"
TAG="antiddos"

load_conf() {
    TCP_PORTS="25565"
    UDP_PORTS=""
    WEB_PORTS=""
    SSH_PORT="22"
    CONN_LIMIT=30
    SYN_RATE=15
    SYN_BURST=30
    UDP_RATE=400
    UDP_BURST=800
    ICMP_RATE=5
    BAN_SECONDS=900
    ATTACK_MODE=0
    SYSCTL=1
    PANEL_URL=""
    PANEL_DIR=""
    API_KEY=""
    STATIC_WHITELIST="127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16"
    EXTRA_WHITELIST=""
    NET_IFACE=""
    EXCLUDE_PORTS=""
    BAN_MODE="balanced"

    # shellcheck disable=SC1090
    [[ -f "$CONF" ]] && . "$CONF"

    if [[ "${ATTACK_MODE}" == "1" ]]; then
        ICMP_RATE=1
    fi
}

CONF_CHANGED=0
AGENT_VERSION="$(tr -d '[:space:]' < /etc/antiddos/version 2>/dev/null || echo desconocida)"
load_conf

if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;36m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi
info() { echo -e "${BLUE}[antiddos]${NC} $*"; }
ok()   { echo -e "${GREEN}[antiddos]${NC} $*"; }
warn() { echo -e "${YELLOW}[antiddos]${NC} $*" >&2; }
fail() { echo -e "${RED}[antiddos]${NC} $*" >&2; exit 1; }
log()  { logger -t "$TAG" -- "$*" 2>/dev/null || true; }

ipt()  { iptables -w 5 "$@"; }
have() { command -v "$1" >/dev/null 2>&1; }

require_root() { [[ $EUID -eq 0 ]] || fail "Ejecútalo como root."; }

require_tools() {
    local missing=()
    have iptables || missing+=(iptables)
    have ipset    || missing+=(ipset)
    if ((${#missing[@]})); then
        fail "Faltan: ${missing[*]}. Instálalos (apt install ${missing[*]}) o usa 'antiddos node-install'."
    fi
    if ! ipt -t raw -L -n >/dev/null 2>&1; then
        fail "El kernel no ofrece la tabla 'raw' de iptables (¿contenedor/VPS sin permisos de red?)."
    fi
}

valid_ipv4() {
    [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$ ]] || return 1
    local ip="${1%%/*}" o
    IFS=. read -ra o <<<"$ip"
    for n in "${o[@]}"; do ((10#$n <= 255)) || return 1; done
    return 0
}

create_sets() {
    ipset create -exist vs_white      hash:net
    ipset create -exist vs_white_feed hash:net
    ipset create -exist vs_feed       hash:net
    ipset create -exist vs_ban        hash:ip timeout 0 maxelem 524288
    ipset create -exist vs_ban_ssh    hash:ip timeout 0 maxelem 65536
    ipset create -exist vs_ban_man    hash:ip timeout 0 maxelem 65536
    ipset create -exist vs_trusted    hash:ip timeout 0 maxelem 262144
    ipset create -exist vs_asn        hash:net maxelem "$NET_MAXELEM"
}

FEED_MAXELEM=262144
NET_MAXELEM=1048576

HL_BIG=(--hashlimit-htable-size 32768 --hashlimit-htable-max 262144)
HL_SMALL=(--hashlimit-htable-size 4096 --hashlimit-htable-max 65536)

AMP_SPORTS="17,19,53,111,123,137,161,389,520,1900,3283,3702,5353,11211"

own_ips() {
    local list
    list="$(ip -o -4 addr show 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i == "inet") { sub(/\/.*/, "", $(i + 1)); if ($(i + 1) !~ /^127\./) print $(i + 1) } }')"
    [[ -n "$list" ]] || list="$(hostname -I 2>/dev/null)"
    local one
    for one in $list; do
        echo "$one"
    done
    return 0
}

refresh_own_whitelist() {
    have ipset || return 0
    local own
    for own in $(own_ips); do
        valid_ipv4 "$own" && ipset add -exist vs_white "$own"
    done
    return 0
}

load_whitelist() {
    ipset flush vs_white
    local entry
    for entry in $STATIC_WHITELIST $EXTRA_WHITELIST; do
        valid_ipv4 "$entry" && ipset add -exist vs_white "$entry"
    done
    if [[ -f "$WHITELIST_FILE" ]]; then
        while read -r entry _; do
            [[ -z "$entry" || "$entry" == \#* ]] && continue
            valid_ipv4 "$entry" && ipset add -exist vs_white "$entry"
        done < "$WHITELIST_FILE"
    fi
    local own
    for own in $(own_ips); do
        valid_ipv4 "$own" && ipset add -exist vs_white "$own"
    done
}

reserved_ports() {
    local cfg=/etc/pterodactyl/config.yml api="" sftp="" list
    if [[ -r "$cfg" ]]; then
        api="$(awk '/^api:/ { a = 1; next } /^[A-Za-z]/ { a = 0 } a && $1 == "port:" { print $2; exit }' "$cfg" 2>/dev/null)"
        sftp="$(awk '/^[ \t]+sftp:/ { s = 1; next } /^[A-Za-z]/ { s = 0 } s && $1 == "bind_port:" { print $2; exit }' "$cfg" 2>/dev/null)"
    fi
    [[ "$api" =~ ^[0-9]+$ ]] || api=8080
    [[ "$sftp" =~ ^[0-9]+$ ]] || sftp=2022
    list="${SSH_PORT:-22},80,443,${api},${sftp}"
    [[ -n "${EXCLUDE_PORTS:-}" ]] && list+=",${EXCLUDE_PORTS}"
    printf '%s' "$list"
}

ports_minus() {
    awk -v spec="$1" -v excl="$2" '
        function mark(str, arr,    n, i, p, m, r, lo, hi, q) {
            n = split(str, p, ",")
            for (i = 1; i <= n; i++) {
                if (p[i] == "") continue
                m = split(p[i], r, ":"); lo = r[1] + 0; hi = (m > 1 ? r[2] + 0 : lo)
                if (lo < 1) lo = 1; if (hi > 65535) hi = 65535
                for (q = lo; q <= hi; q++) arr[q] = 1
            }
        }
        BEGIN {
            mark(spec, a); mark(excl, e); out = ""; start = 0
            for (q = 1; q <= 65536; q++) {
                ok = (q <= 65535 && (q in a) && !(q in e))
                if (ok && !start) start = q
                if (!ok && start) { end = q - 1; out = out (out == "" ? "" : ",") (start == end ? start : start ":" end); start = 0 }
            }
            print out
        }'
}

ports_chunks() {
    awk -v spec="$1" 'BEGIN {
        n = split(spec, p, ","); w = 0; line = ""
        for (i = 1; i <= n; i++) {
            if (p[i] == "") continue
            cw = (index(p[i], ":") ? 2 : 1)
            if (w + cw > 15) { print line; line = ""; w = 0 }
            line = line (line == "" ? "" : ",") p[i]; w += cw
        }
        if (line != "") print line
    }'
}

remove_rules() {
    while ipt -t raw    -D PREROUTING -j ANTIDDOS   2>/dev/null; do :; done
    while ipt -t mangle -D PREROUTING -j ANTIDDOS_M 2>/dev/null; do :; done
    local spec
    for spec in "raw ANTIDDOS" "raw VS_SYNFLOOD" "raw VS_SSHBAN" "raw ANTIDDOS_AMP" "raw ANTIDDOS_D_SLIM" "raw ANTIDDOS_CTCP" "raw ANTIDDOS_CUDP" "raw ANTIDDOS_CSYN" \
                 "raw ANTIDDOS_D_BAN" "raw ANTIDDOS_D_MAL" "raw ANTIDDOS_D_ICMP" "raw ANTIDDOS_D_UDP" "raw ANTIDDOS_D_WEB" "mangle ANTIDDOS_M" "mangle ANTIDDOS_M_INV"; do
        # shellcheck disable=SC2086
        set -- $spec
        ipt -t "$1" -F "$2" 2>/dev/null
        ipt -t "$1" -X "$2" 2>/dev/null
    done
    return 0
}

apply_rules() {
    remove_rules

    ipt -t raw -N VS_SYNFLOOD
    ipt -t raw -N VS_SSHBAN
    ipt -t raw -N ANTIDDOS_CTCP
    ipt -t raw -N ANTIDDOS_CUDP
    ipt -t raw -N ANTIDDOS_CSYN
    ipt -t raw -N ANTIDDOS
    ipt -t raw -N ANTIDDOS_AMP
    ipt -t mangle -N ANTIDDOS_M
    ipt -t mangle -N ANTIDDOS_M_INV
    ipt -t mangle -A ANTIDDOS_M_INV -j DROP

    local reason
    for reason in BAN MAL ICMP UDP WEB SLIM; do
        ipt -t raw -N "ANTIDDOS_D_${reason}"
        ipt -t raw -A "ANTIDDOS_D_${reason}" -j DROP
    done

    ipt -t raw -A VS_SYNFLOOD -m limit --limit 6/min --limit-burst 3 -j LOG --log-prefix "ANTIDDOS ban syn " --log-level 4
    ipt -t raw -A VS_SYNFLOOD -j SET --add-set vs_ban src --exist --timeout "$BAN_SECONDS"
    ipt -t raw -A VS_SYNFLOOD -j DROP
    ipt -t raw -A VS_SSHBAN -m limit --limit 6/min --limit-burst 3 -j LOG --log-prefix "ANTIDDOS ban ssh " --log-level 4
    ipt -t raw -A VS_SSHBAN -j SET --add-set vs_ban_ssh src --exist --timeout "$BAN_SECONDS"
    ipt -t raw -A VS_SSHBAN -j DROP

    local R=(ipt -t raw -A ANTIDDOS)

    "${R[@]}" -m set --match-set vs_white src      -j RETURN
    "${R[@]}" -m set --match-set vs_white_feed src -j RETURN
    "${R[@]}" -m set --match-set vs_feed src       -j ANTIDDOS_D_BAN
    "${R[@]}" -m set --match-set vs_asn src        -j ANTIDDOS_D_BAN
    "${R[@]}" -m set --match-set vs_ban src        -j ANTIDDOS_D_BAN
    "${R[@]}" -m set --match-set vs_ban_ssh src    -j ANTIDDOS_D_BAN
    "${R[@]}" -m set --match-set vs_ban_man src    -j ANTIDDOS_D_BAN

    "${R[@]}" -f -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ALL NONE        -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ALL ALL         -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ALL FIN,PSH,URG -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags SYN,FIN SYN,FIN -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags SYN,RST SYN,RST -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags FIN,RST FIN,RST -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ACK,FIN FIN     -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ACK,URG URG     -j ANTIDDOS_D_MAL

    "${R[@]}" -p icmp --icmp-type echo-request \
        -m hashlimit --hashlimit-name vs_icmp --hashlimit-mode srcip "${HL_SMALL[@]}" \
        --hashlimit-above "${ICMP_RATE}/second" --hashlimit-burst 20 -j ANTIDDOS_D_ICMP

    local tcp_eff udp_eff c
    tcp_eff="$(ports_minus "$TCP_PORTS" "$(reserved_ports)")"
    udp_eff="$(ports_minus "$UDP_PORTS" "$(reserved_ports)")"
    local -a TCPC=() UDPC=()
    [[ -n "$tcp_eff" ]] && mapfile -t TCPC < <(ports_chunks "$tcp_eff")
    [[ -n "$udp_eff" ]] && mapfile -t UDPC < <(ports_chunks "$udp_eff")

    local a_syn_rate=$(( SYN_RATE / 2 > 5 ? SYN_RATE / 2 : 5 )) a_syn_burst=$(( SYN_BURST / 2 > 10 ? SYN_BURST / 2 : 10 ))
    local a_udp_rate=$(( UDP_RATE / 2 > 200 ? UDP_RATE / 2 : 200 )) a_udp_burst=$(( UDP_BURST / 2 > 400 ? UDP_BURST / 2 : 400 ))
    local a_conn=$(( CONN_LIMIT / 2 > 10 ? CONN_LIMIT / 2 : 10 ))
    local -a UNKNOWN=(-m set ! --match-set vs_trusted src)

    for c in "${TCPC[@]}"; do
        "${R[@]}" -p tcp -m multiport --dports "$c" -j ANTIDDOS_CTCP
        "${R[@]}" -p tcp --syn -m multiport --dports "$c" -j ANTIDDOS_CSYN
    done
    for c in "${UDPC[@]}"; do
        "${R[@]}" -p udp -m multiport --dports "$c" -j ANTIDDOS_CUDP
    done

    ipt -t raw -A ANTIDDOS_AMP -p udp -m multiport --sports "$AMP_SPORTS" -j ANTIDDOS_D_UDP
    for c in "${UDPC[@]}" "${TCPC[@]}"; do
        "${R[@]}" -p udp -m multiport --dports "$c" -j ANTIDDOS_AMP
    done

    local ban_rate="$SYN_RATE" ban_burst="$SYN_BURST" ssh_rate="6/minute" ssh_burst=8
    if [[ "$BAN_MODE" != "strict" ]]; then
        ban_rate=$(( SYN_RATE * 3 )); ban_burst=$(( SYN_BURST * 3 )); ssh_rate="20/minute"; ssh_burst=20
    fi

    for c in "${TCPC[@]}"; do
        if [[ "$BAN_MODE" != "monitor" ]]; then
            "${R[@]}" -p tcp --syn -m multiport --dports "$c" \
                -m hashlimit --hashlimit-name vs_synban --hashlimit-mode srcip "${HL_BIG[@]}" \
                --hashlimit-above "${ban_rate}/second" --hashlimit-burst "$ban_burst" \
                -j VS_SYNFLOOD
        fi
        if [[ "$BAN_MODE" != "strict" ]]; then
            "${R[@]}" -p tcp --syn -m multiport --dports "$c" \
                -m hashlimit --hashlimit-name vs_syn --hashlimit-mode srcip "${HL_BIG[@]}" \
                --hashlimit-above "${SYN_RATE}/second" --hashlimit-burst "$SYN_BURST" \
                -j ANTIDDOS_D_SLIM
        fi
        if [[ "${ATTACK_MODE}" == "1" ]]; then
            "${R[@]}" -p tcp --syn -m multiport --dports "$c" "${UNKNOWN[@]}" \
                -m hashlimit --hashlimit-name vs_synA --hashlimit-mode srcip "${HL_BIG[@]}" \
                --hashlimit-above "${a_syn_rate}/second" --hashlimit-burst "$a_syn_burst" \
                -j ANTIDDOS_D_SLIM
            "${R[@]}" -p tcp --syn -m multiport --dports "$c" "${UNKNOWN[@]}" \
                -m hashlimit --hashlimit-name vs_syn24 --hashlimit-mode srcip --hashlimit-srcmask 24 "${HL_BIG[@]}" \
                --hashlimit-above "$(( SYN_RATE * 4 ))/second" --hashlimit-burst "$(( SYN_BURST * 4 ))" \
                -j ANTIDDOS_D_SLIM
        fi
    done

    if [[ -n "$SSH_PORT" ]]; then
        if [[ "$BAN_MODE" != "monitor" ]]; then
            "${R[@]}" -p tcp --syn --dport "$SSH_PORT" \
                -m hashlimit --hashlimit-name vs_sshban --hashlimit-mode srcip "${HL_SMALL[@]}" \
                --hashlimit-above "$ssh_rate" --hashlimit-burst "$ssh_burst" \
                -j VS_SSHBAN
        fi
        if [[ "$BAN_MODE" != "strict" ]]; then
            "${R[@]}" -p tcp --syn --dport "$SSH_PORT" \
                -m hashlimit --hashlimit-name vs_ssh --hashlimit-mode srcip "${HL_SMALL[@]}" \
                --hashlimit-above 6/minute --hashlimit-burst 8 \
                -j ANTIDDOS_D_SLIM
        fi
    fi

    if [[ -n "$WEB_PORTS" ]]; then
        "${R[@]}" -p tcp --syn -m multiport --dports "$WEB_PORTS" \
            -m hashlimit --hashlimit-name vs_web --hashlimit-mode srcip "${HL_SMALL[@]}" \
            --hashlimit-above 60/second --hashlimit-burst 120 -j ANTIDDOS_D_WEB
    fi

    for c in "${UDPC[@]}"; do
        "${R[@]}" -p udp -m multiport --dports "$c" \
            -m hashlimit --hashlimit-name vs_udp --hashlimit-mode srcip "${HL_BIG[@]}" \
            --hashlimit-above "${UDP_RATE}/second" --hashlimit-burst "$UDP_BURST" -j ANTIDDOS_D_UDP
        if [[ "${ATTACK_MODE}" == "1" ]]; then
            "${R[@]}" -p udp -m multiport --dports "$c" "${UNKNOWN[@]}" \
                -m hashlimit --hashlimit-name vs_udpA --hashlimit-mode srcip "${HL_BIG[@]}" \
                --hashlimit-above "${a_udp_rate}/second" --hashlimit-burst "$a_udp_burst" -j ANTIDDOS_D_UDP
        fi
    done

    if (( ${#TCPC[@]} + ${#UDPC[@]} > 0 )); then
        ipt -t mangle -A ANTIDDOS_M -m set --match-set vs_white src -j RETURN
        ipt -t mangle -A ANTIDDOS_M -m set --match-set vs_white_feed src -j RETURN
    fi
    for c in "${TCPC[@]}"; do
        ipt -t mangle -A ANTIDDOS_M -p tcp -m multiport --dports "$c" -m conntrack --ctstate ESTABLISHED \
            -m set ! --match-set vs_trusted src -m limit --limit 500/second --limit-burst 1000 \
            -j SET --add-set vs_trusted src --exist --timeout 21600
        ipt -t mangle -A ANTIDDOS_M -p tcp -m multiport --dports "$c" \
            -m conntrack --ctstate INVALID -j ANTIDDOS_M_INV
        if [[ "${ATTACK_MODE}" == "1" ]]; then
            ipt -t mangle -A ANTIDDOS_M -p tcp --syn -m multiport --dports "$c" "${UNKNOWN[@]}" \
                -m connlimit --connlimit-above "$a_conn" --connlimit-mask 32 --connlimit-saddr -j DROP
        fi
        ipt -t mangle -A ANTIDDOS_M -p tcp --syn -m multiport --dports "$c" \
            -m connlimit --connlimit-above "$CONN_LIMIT" --connlimit-mask 32 --connlimit-saddr -j DROP
    done
    for c in "${UDPC[@]}"; do
        ipt -t mangle -A ANTIDDOS_M -p udp -m multiport --dports "$c" -m conntrack --ctstate ESTABLISHED \
            -m set ! --match-set vs_trusted src -m limit --limit 500/second --limit-burst 1000 \
            -j SET --add-set vs_trusted src --exist --timeout 21600
    done

    ipt -t raw    -I PREROUTING 1 -j ANTIDDOS
    ipt -t mangle -I PREROUTING 1 -j ANTIDDOS_M
}

apply_sysctl() {
    [[ "$SYSCTL" == "1" ]] || return 0
    mkdir -p "$(dirname "$SYSCTL_FILE")"
    cat > "$SYSCTL_FILE" <<'EOF'
# Generado por Anti-DDoS y Optimización — no editar a mano (se regenera con: antiddos-fw apply)
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 65536
net.ipv4.tcp_synack_retries = 2
net.ipv4.tcp_syn_retries = 3
net.ipv4.tcp_rfc1337 = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1
net.ipv4.tcp_fin_timeout = 15
net.core.somaxconn = 16384
# Cola de paquetes por CPU y paquetes procesados por vuelta: bajo un flood grande
# la tarjeta no descarta a ciegas lo que el firewall aun no ha visto.
net.core.netdev_max_backlog = 65536
net.core.netdev_budget = 600
net.core.netdev_budget_usecs = 4000
net.netfilter.nf_conntrack_max = 1048576
# Un ACK suelto ya no «adopta» una conexion inexistente: los floods de ACK pasan a
# ser INVALID y se descartan sin llenar la tabla de conexiones.
net.netfilter.nf_conntrack_tcp_loose = 0
# 6 h sin trafico en vez de 5 dias: las conexiones muertas no ocupan la tabla.
net.netfilter.nf_conntrack_tcp_timeout_established = 21600
net.netfilter.nf_conntrack_tcp_timeout_syn_recv = 30
net.netfilter.nf_conntrack_tcp_timeout_syn_sent = 30
net.netfilter.nf_conntrack_tcp_timeout_time_wait = 30
net.netfilter.nf_conntrack_tcp_timeout_close_wait = 30
net.netfilter.nf_conntrack_tcp_timeout_fin_wait = 30
net.netfilter.nf_conntrack_udp_timeout = 20
net.netfilter.nf_conntrack_udp_timeout_stream = 60
EOF
    sysctl -e -q -p "$SYSCTL_FILE" >/dev/null 2>&1 || warn "Algunos ajustes de kernel no se pudieron aplicar."
    if [[ -w /sys/module/nf_conntrack/parameters/hashsize ]]; then
        echo 262144 > /sys/module/nf_conntrack/parameters/hashsize 2>/dev/null || true
    fi
}

panel_artisan() {
    local owner; owner="$(stat -c '%U' "$PANEL_DIR/public" 2>/dev/null || echo root)"
    if [[ "$owner" != "root" ]] && have runuser; then
        ( cd "$PANEL_DIR" && runuser -u "$owner" -- php artisan antiddos:manage "$@" )
    else
        ( cd "$PANEL_DIR" && php artisan antiddos:manage "$@" )
    fi
}

panel_is_local() { [[ -n "$PANEL_DIR" && -f "$PANEL_DIR/artisan" ]] && have php; }

fetch_feed() {
    if panel_is_local; then
        panel_artisan feed --host="$(hostname)" 2>&1
        return
    fi
    have curl || { echo "curl no esta instalado"; return 1; }
    curl -fsS --max-time 20 -G --data-urlencode "host=$(hostname)" -H "X-Antiddos-Key: ${API_KEY}" "${PANEL_URL%/}/api/antiddos/feed" 2>&1
}

request_self_update() {
    local lock="/run/antiddos/update.lock"
    [[ -f "$lock" ]] && return 0
    command -v antiddos >/dev/null 2>&1 || { log "actualizacion pedida desde el panel, pero no se encuentra el comando antiddos"; return 0; }
    mkdir -p /run/antiddos /var/log/antiddos 2>/dev/null || true
    log "actualizacion del agente pedida desde el panel: se lanza en segundo plano"
    ( touch "$lock" 2>/dev/null; antiddos update --force >> /var/log/antiddos/self-update.log 2>&1; rm -f "$lock" 2>/dev/null ) \
        < /dev/null > /dev/null 2>&1 &
    disown 2>/dev/null || true
}

conf_get() { grep -m1 -E "^$1=" "$CONF" 2>/dev/null | cut -d= -f2- | tr -d '"'; }

apply_remote_setting() {
    local key="$1" val="$2" re
    case "$key" in
        TCP_PORTS|UDP_PORTS) re='^[0-9:,]*$' ;;
        CONN_LIMIT|SYN_RATE|UDP_RATE|BAN_SECONDS) re='^[0-9]+$' ;;
        ATTACK_MODE) re='^[01]$' ;;
        BAN_MODE) re='^(monitor|balanced|strict)$' ;;
        *) return 0 ;;
    esac
    [[ "$val" == "-" ]] && val=""
    [[ "$val" =~ $re ]] || { warn "Ajuste del panel ignorado (valor no valido): ${key}"; return 0; }
    [[ "$(conf_get "$key")" == "$val" ]] && return 0
    set_conf "$key" "$val"
    CONF_CHANGED=1
    log "ajuste del panel: ${key}=${val:-<vacio>}"
}

FEED_SUM="/run/antiddos/feed.sum"

sync_feed() {
    [[ -n "$PANEL_DIR" || ( -n "$PANEL_URL" && -n "$API_KEY" ) ]] || return 0

    local body
    if [[ $# -gt 0 ]]; then
        body="$1"
    elif ! body="$(fetch_feed)"; then
        warn "No se pudo obtener la lista del panel: ${body:0:200}"
        log "feed: fallo de descarga"
        return 1
    fi

    if [[ -n "$body" ]] && ! grep -qE '^[BWACU] ' <<<"$body"; then
        warn "Respuesta del panel no válida: ${body:0:200}"
        log "feed: respuesta no valida"
        return 1
    fi

    local sum prev=""
    sum="$( { cat "$CONF" 2>/dev/null; printf '%s' "$body"; } | md5sum)"; sum="${sum%% *}"
    [[ -r "$FEED_SUM" ]] && read -r prev < "$FEED_SUM"
    if [[ -n "$sum" && "$sum" == "$prev" && "$(count_set vs_white_feed)" != "0" ]]; then
        return 0
    fi

    local restore s
    restore="$(mktemp /tmp/antiddos-feed.XXXXXX)" || return 1
    for s in vs_feed_tmp vs_white_feed_tmp vs_asn_tmp; do ipset destroy "$s" 2>/dev/null || true; done

    local kind addr val bans=0 whites=0 nets=0
    {
        echo "create vs_feed_tmp hash:net maxelem ${FEED_MAXELEM}"
        echo "create vs_white_feed_tmp hash:net maxelem ${FEED_MAXELEM}"
        echo "create vs_asn_tmp hash:net maxelem ${NET_MAXELEM}"
        while read -r kind addr val _; do
            case "$kind" in
                B) valid_ipv4 "${addr:-x}" && { echo "add vs_feed_tmp ${addr}"; bans=$((bans + 1)); } ;;
                W) valid_ipv4 "${addr:-x}" && { echo "add vs_white_feed_tmp ${addr}"; whites=$((whites + 1)); } ;;
                A) valid_ipv4 "${addr:-x}" && [[ "$addr" == */* && "${addr#*/}" -ge 8 ]] && { echo "add vs_asn_tmp ${addr}"; nets=$((nets + 1)); } ;;
                C) apply_remote_setting "${addr:-}" "${val:-}" >&2 ;;
                U) [[ "${addr:-}" == "1" ]] && request_self_update >&2 ;;
            esac
        done <<<"$body"
    } > "$restore"

    if ! ipset -exist restore < "$restore" 2>/dev/null; then
        warn "No se pudo cargar la lista del panel en el firewall (ipset restore); se conservan los bloqueos anteriores."
        log "feed: fallo de ipset restore"
        for s in vs_feed_tmp vs_white_feed_tmp vs_asn_tmp; do ipset destroy "$s" 2>/dev/null || true; done
        rm -f "$restore"
        return 1
    fi
    rm -f "$restore"

    ipset swap vs_feed_tmp vs_feed && ipset destroy vs_feed_tmp
    ipset swap vs_white_feed_tmp vs_white_feed && ipset destroy vs_white_feed_tmp
    ipset swap vs_asn_tmp vs_asn && ipset destroy vs_asn_tmp
    log "feed: ${bans} bloqueos, ${nets} rangos de operadores/paises, ${whites} en lista blanca"

    mkdir -p "${FEED_SUM%/*}" 2>/dev/null
    sum="$( { cat "$CONF" 2>/dev/null; printf '%s' "$body"; } | md5sum)"
    printf '%s\n' "${sum%% *}" > "$FEED_SUM" 2>/dev/null || true
    return 0
}

json_arr() { local out="" x; for x in "$@"; do out+="${out:+,}\"${x}\""; done; echo "[${out}]"; }

count_bans() {
    ipset -t list 2>/dev/null | awk '/^Name:/ { n = $2 } /^Number of entries:/ && (n == "vs_ban" || n == "vs_ban_ssh" || n == "vs_ban_man") { s += $4 } END { print s + 0 }'
}

fw_bans_json() {
    local pair set why
    {
        for pair in "vs_ban:syn" "vs_ban_ssh:ssh" "vs_ban_man:manual"; do
            set="${pair%%:*}"; why="${pair##*:}"
            ipset list "$set" 2>/dev/null | awk -v why="$why" '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/ { print $1, ($3 == "" ? 0 : $3), why; if (++n >= 30) exit }'
        done
    } | head -30 | awk 'BEGIN { printf "[" } { printf "%s{\"ip\":\"%s\",\"left\":%d,\"why\":\"%s\"}", (NR > 1 ? "," : ""), $1, $2, $3 } END { print "]" }'
}

count_set() { ipset -t list "$1" 2>/dev/null | awk '/^Number of entries:/ { print $4; exit }'; }

dropped_packets() {
    read_counters | awk '{print $6 + $7 + $8 + $9 + $10 + $11 + $12}'
}

build_heartbeat() {
    local active=false mode=http ips bl bp bn c top net fwb
    c="$(read_counters)"; net="$(read_net)"
    bl="$(count_bans)"; bp="$(count_set vs_feed)"; bn="$(count_set vs_asn)"; top="$(top_ips_json)"; fwb="$(fw_bans_json)"
    ipt -t raw -L ANTIDDOS -n >/dev/null 2>&1 && active=true
    panel_is_local && mode=local
    # shellcheck disable=SC2046
    ips="$(json_arr $(own_ips))"
    # shellcheck disable=SC2086
    set -- $c
    local dropped=$(( ${6:-0} + ${7:-0} + ${8:-0} + ${9:-0} + ${10:-0} + ${11:-0} + ${12:-0} ))
    printf '{"host":"%s","ips":%s,"version":"%s","active":%s,"mode":"%s","tcp":"%s","udp":"%s","attack":%s,"conn_limit":%s,"syn_rate":%s,"udp_rate":%s,"ban_seconds":%s,"bans_local":%s,"bans_panel":%s,"bans_net":%s,"dropped":%s,"tcp_pkts":%s,"udp_pkts":%s,"syn":%s,"tcp_bytes":%s,"udp_bytes":%s,"drop_bytes":%s,"d_ban":%s,"d_mal":%s,"d_icmp":%s,"d_syn":%s,"d_udp":%s,"d_conn":%s,"d_web":%s,"rx_bytes":%s,"rx_pkts":%s,"tx_bytes":%s,"tx_pkts":%s,"ban_mode":"%s","fw_bans":%s,"top_ips":%s}' \
        "$(hostname)" "$ips" "$AGENT_VERSION" "$active" "$mode" "$TCP_PORTS" "$UDP_PORTS" "${ATTACK_MODE:-0}" \
        "$CONN_LIMIT" "$SYN_RATE" "$UDP_RATE" "$BAN_SECONDS" "${bl:-0}" "${bp:-0}" "${bn:-0}" "$dropped" \
        "${1:-0}" "${2:-0}" "${3:-0}" "${4:-0}" "${5:-0}" "${13:-0}" "${6:-0}" "${7:-0}" "${8:-0}" "${9:-0}" "${10:-0}" "${11:-0}" "${12:-0}" $net "$BAN_MODE" "$fwb" "$top"
}

send_heartbeat() {
    [[ -n "$PANEL_DIR" || ( -n "$PANEL_URL" && -n "$API_KEY" ) ]] || return 0
    local json; json="$(build_heartbeat)"
    if panel_is_local; then
        panel_artisan heartbeat --payload="$json" >/dev/null 2>&1 || true
    elif have curl; then
        curl -fsS --max-time 15 -X POST -H "X-Antiddos-Key: ${API_KEY}" -H "Content-Type: application/json" \
            --data "$json" "${PANEL_URL%/}/api/antiddos/heartbeat" >/dev/null 2>&1 || true
    fi
    return 0
}

LIVE_DIR="/var/lib/antiddos"
LIVE_RING="${LIVE_DIR}/live.ring"
LIVE_JSON="${LIVE_DIR}/live.json"
LIVE_EVERY=1
LIVE_KEEP=300

read_counters() {
    { ipt -t raw -L -v -n -x 2>/dev/null; ipt -t mangle -L -v -n -x 2>/dev/null; } | awk '
        /^Chain / { chain = $2; next }
        chain == "ANTIDDOS" {
            if ($3 == "ANTIDDOS_CTCP") { tcp += $1; tcpb += $2 }
            if ($3 == "ANTIDDOS_CUDP") { udp += $1; udpb += $2 }
            if ($3 == "ANTIDDOS_CSYN") { syn += $1 }
        }
        $3 == "DROP" {
            if (chain == "ANTIDDOS_D_BAN")  { dban  += $1; dbytes += $2 }
            if (chain == "ANTIDDOS_D_MAL")  { dmal  += $1; dbytes += $2 }
            if (chain == "ANTIDDOS_D_ICMP") { dicmp += $1; dbytes += $2 }
            if (chain == "ANTIDDOS_D_UDP")  { dudp  += $1; dbytes += $2 }
            if (chain == "ANTIDDOS_D_WEB")  { dweb  += $1; dbytes += $2 }
            if (chain == "VS_SYNFLOOD" || chain == "VS_SSHBAN" || chain == "ANTIDDOS_D_SLIM") { dsyn += $1; dbytes += $2 }
            # mangle: limite de conexiones simultaneas y TCP fuera de conexion (cuenta como malformado).
            if (chain == "ANTIDDOS_M")      { dconn += $1; dbytes += $2 }
            if (chain == "ANTIDDOS_M_INV")  { dmal  += $1; dbytes += $2 }
        }
        END { printf "%d %d %d %d %d %d %d %d %d %d %d %d %d\n", tcp, udp, syn, tcpb, udpb, dban, dmal, dicmp, dsyn, dudp, dconn, dweb, dbytes }'
}

net_iface() {
    if [[ -n "${NET_IFACE:-}" ]]; then printf '%s' "$NET_IFACE"; return; fi
    local dev
    dev="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit }}')"
    [[ -z "$dev" ]] && dev="$(awk -F'[: ]+' 'NR > 2 && $2 != "lo" && $2 !~ /^(docker|veth|br-|pterodactyl)/ { print $2; exit }' /proc/net/dev 2>/dev/null)"
    printf '%s' "$dev"
}

read_net() {
    read_net_var "${1:-$(net_iface)}"
    echo "$NET"
}

read_net_var() {
    local dev="$1" line name
    local -a f
    NET="0 0 0 0"
    [[ -n "$dev" && -r /proc/net/dev ]] || return 0
    while IFS= read -r line; do
        name="${line%%:*}"; name="${name//[[:space:]]/}"
        [[ "$line" == *:* && "$name" == "$dev" ]] || continue
        read -r -a f <<<"${line#*:}"
        NET="${f[0]:-0} ${f[1]:-0} ${f[8]:-0} ${f[9]:-0}"
        return 0
    done < /proc/net/dev
}

now_ms() { now_ms_var; printf '%s' "$NOW_MS"; }

now_ms_var() {
    local t="${EPOCHREALTIME:-}"
    if [[ -n "$t" ]]; then t="${t/,/.}"; NOW_MS="${t%???}"; else NOW_MS="$(date +%s.%3N 2>/dev/null || date +%s)"; fi
}

top_ips_json() {
    [[ -r /proc/net/nf_conntrack ]] || { echo "[]"; return; }
    awk -v spec="${TCP_PORTS},${UDP_PORTS}" '
        BEGIN { n = split(spec, parts, ","); for (i = 1; i <= n; i++) { if (parts[i] == "") continue; m = split(parts[i], r, ":"); lo[++k] = r[1] + 0; hi[k] = (m > 1 ? r[2] + 0 : r[1] + 0) } }
        ($3 == "tcp" || $3 == "udp") {
            s = ""; d = ""
            for (i = 1; i <= NF; i++) { if (s == "" && $i ~ /^src=/) s = substr($i, 5); if (d == "" && $i ~ /^dport=/) d = substr($i, 7) }
            if (d == "" || s ~ /:/ || s ~ /^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/) next
            d += 0
            for (j = 1; j <= k; j++) if (d >= lo[j] && d <= hi[j]) { c[s]++; break }
        }
        END { for (ip in c) print c[ip], ip }' /proc/net/nf_conntrack 2>/dev/null \
        | sort -rn | head -10 \
        | awk 'BEGIN { printf "[" } { printf "%s{\"ip\":\"%s\",\"conns\":%d}", (NR > 1 ? "," : ""), $2, $1 } END { print "]" }'
}

cmd_watch() {
    require_root; require_tools
    umask 022
    mkdir -p "$LIVE_DIR"; chmod 755 "$LIVE_DIR"
    rm -f "$LIVE_RING"
    info "Modo en vivo activo: contadores cada ${LIVE_EVERY} s (${LIVE_JSON})."

    local loops=0 line host dev bans_now=0 obj joined oldifs remote sfd=""
    local -a ring=() c=()
    host="$(hostname)"
    dev="$(net_iface)"

    exec {sfd}<> <(:) || sfd=""

    while true; do
        load_conf
        remote=0
        if ! panel_is_local && [[ -n "$PANEL_URL" && -n "$API_KEY" ]] && have curl; then remote=1; fi

        now_ms_var
        line="$(read_counters)"
        read_net_var "$dev"
        (( loops % 60 == 0 )) && dev="$(net_iface)"
        (( loops % 5 == 0 )) && bans_now="$(count_bans)"
        loops=$((loops + 1))

        read -r -a c <<<"${line} ${NET}"
        printf -v obj '{"t":%s,"tcp":%s,"udp":%s,"syn":%s,"tcp_b":%s,"udp_b":%s,"d_ban":%s,"d_mal":%s,"d_icmp":%s,"d_syn":%s,"d_udp":%s,"d_conn":%s,"d_web":%s,"d_bytes":%s,"rx_b":%s,"rx_p":%s,"tx_b":%s,"tx_p":%s,"bans":%s}' \
            "$NOW_MS" "${c[0]:-0}" "${c[1]:-0}" "${c[2]:-0}" "${c[3]:-0}" "${c[4]:-0}" "${c[5]:-0}" "${c[6]:-0}" "${c[7]:-0}" "${c[8]:-0}" \
            "${c[9]:-0}" "${c[10]:-0}" "${c[11]:-0}" "${c[12]:-0}" "${c[13]:-0}" "${c[14]:-0}" "${c[15]:-0}" "${c[16]:-0}" "${bans_now:-0}"

        ring+=("$obj")
        if (( ${#ring[@]} > LIVE_KEEP )); then ring=("${ring[@]: -LIVE_KEEP}"); fi

        oldifs="$IFS"; IFS=,; joined="${ring[*]}"; IFS="$oldifs"
        printf '{"host":"%s","samples":[%s]}\n' "$host" "$joined" > "${LIVE_JSON}.tmp" && mv -f "${LIVE_JSON}.tmp" "$LIVE_JSON"

        if [[ "$remote" == "1" ]]; then
            ( curl -fsS --max-time 2 -X POST -H "X-Antiddos-Key: ${API_KEY}" -H "Content-Type: application/json" \
                --data "{\"host\":\"${host}\",${obj:1}" "${PANEL_URL%/}/api/antiddos/live" >/dev/null 2>&1 & )
        fi

        if [[ -n "$sfd" ]]; then read -r -t "$LIVE_EVERY" -u "$sfd" _ || true; else sleep "$LIVE_EVERY"; fi
    done
}

cmd_sync() {
    require_root; require_tools
    create_sets
    refresh_own_whitelist

    local body sent=0
    if panel_is_local && body="$(panel_artisan sync --host="$(hostname)" --payload="$(build_heartbeat)" 2>&1)" && grep -qE '^[BWACU] ' <<<"$body"; then
        sync_feed "$body" || true
        sent=1
    else
        sync_feed || true
    fi

    if [[ "$CONF_CHANGED" == "1" ]]; then
        load_conf
        load_whitelist
        apply_rules
        apply_sysctl
        log "reglas reaplicadas por cambios del panel (tcp=${TCP_PORTS} udp=${UDP_PORTS} attack=${ATTACK_MODE})"
    fi
    [[ "$sent" == "1" ]] || send_heartbeat
}

cmd_apply() {
    require_root; require_tools
    rm -f "$FEED_SUM"
    create_sets
    load_whitelist
    sync_feed || true
    [[ "$CONF_CHANGED" == "1" ]] && { load_conf; load_whitelist; }
    apply_rules
    apply_sysctl
    ok "Escudo activo. TCP: ${TCP_PORTS:-—}  UDP: ${UDP_PORTS:-—}  límite conexiones/IP: ${CONN_LIMIT}  modo ataque: $([[ $ATTACK_MODE == 1 ]] && echo SÍ || echo no)"
    log "apply (tcp=${TCP_PORTS} udp=${UDP_PORTS} attack=${ATTACK_MODE})"
    send_heartbeat
}

cmd_remove() {
    require_root
    rm -f "$FEED_SUM"
    have iptables && remove_rules
    if have ipset; then
        local s
        for s in vs_white vs_white_feed vs_feed vs_asn vs_ban vs_ban_ssh vs_ban_man vs_trusted vs_feed_tmp vs_white_feed_tmp vs_asn_tmp; do
            ipset destroy "$s" 2>/dev/null || true
        done
    fi
    rm -f "$SYSCTL_FILE"
    ok "Escudo desactivado: reglas y sets eliminados."
    log "remove"
}

cmd_status() {
    require_root
    echo "Anti-DDoS y Optimización — firewall del nodo"
    if have iptables && ipt -t raw -L ANTIDDOS -n >/dev/null 2>&1; then
        echo "  Estado          : ACTIVO"
    else
        echo "  Estado          : inactivo (ejecuta: antiddos fw apply)"
    fi
    echo "  Puertos TCP     : ${TCP_PORTS:-—}"
    echo "  Puertos UDP     : ${UDP_PORTS:-—}"
    echo "  Puerto web      : ${WEB_PORTS:-sin límite}"
    echo "  Modo ataque     : $([[ $ATTACK_MODE == 1 ]] && echo SÍ || echo no)"
    echo "  Política bloqueo: ${BAN_MODE} (monitor = nunca bloquea · balanced = solo floods claros · strict = al primer exceso)"
    echo "  Límites         : conn=${CONN_LIMIT}/IP  syn=${SYN_RATE}/s (ráfaga ${SYN_BURST})  udp=${UDP_RATE}/s  ban=${BAN_SECONDS}s"
    if [[ "$ATTACK_MODE" == "1" ]]; then
        echo "  Modo ataque     : límites más estrictos SOLO para IPs desconocidas; los jugadores ya conectados conservan los normales"
    fi
    echo "  Jugadores conocidos: $(count_set vs_trusted) IPs (sesión establecida reciente, exentas de los límites extra del modo ataque)"
    echo "  TCP efectivos   : $(ports_minus "$TCP_PORTS" "$(reserved_ports)") (sin los reservados: $(reserved_ports))"
    echo "  Panel (sync)    : ${PANEL_URL:-no configurado}"
    if have ipset; then
        local s n
        for s in vs_ban vs_ban_ssh vs_ban_man vs_feed vs_asn vs_white vs_white_feed; do
            n="$(ipset list "$s" 2>/dev/null | awk '/^Number of entries:/ {print $4}')"
            printf '  %-15s : %s entradas\n' "$s" "${n:-—}"
        done
    fi
    if have iptables && ipt -t raw -L ANTIDDOS -n >/dev/null 2>&1; then
        echo
        echo "Paquetes frenados (desde el último apply): $(dropped_packets)"
        echo "Tráfico de confianza que se deja pasar sin límites (lista blanca): $(ipt -t raw -L ANTIDDOS -v -n -x 2>/dev/null | awk '$3=="RETURN" {s+=$1} END{print s+0}') paquetes"
        ipt -t raw -L ANTIDDOS -v -n 2>/dev/null | awk 'NR>2 && $1+0 > 0 && $3!="RETURN" {print "  " $0}'
        ipt -t mangle -L ANTIDDOS_M -v -n 2>/dev/null | awk 'NR>2 && $1+0 > 0 && $3!="RETURN" {print "  " $0}'
    fi
}

cmd_top() {
    require_root
    [[ -r /proc/net/nf_conntrack ]] || fail "No hay /proc/net/nf_conntrack (conntrack no está cargado)."
    local ports re
    ports="$(echo "$TCP_PORTS,$UDP_PORTS" | tr ',' '\n' | grep -E '^[0-9]+$' | paste -sd'|' -)"
    [[ -n "$ports" ]] || fail "No hay puertos protegidos configurados."
    re="^(${ports})$"
    echo "Conexiones por IP hacia los puertos protegidos (top 20):"
    awk -v re="$re" '
        $3=="tcp" || $3=="udp" {
            s=""; d=""
            for (i=1;i<=NF;i++) {
                if (s=="" && $i ~ /^src=/)   s=substr($i,5)
                if (d=="" && $i ~ /^dport=/) d=substr($i,7)
            }
            if (d ~ re) c[s]++
        }
        END { for (k in c) printf "%6d  %s\n", c[k], k }' /proc/net/nf_conntrack | sort -rn | head -20
}

cmd_ban() {
    require_root; require_tools
    local ip="${1:-}" secs="${2:-$BAN_SECONDS}"
    valid_ipv4 "$ip" || fail "IP no válida (solo IPv4): '${ip}'"
    [[ "$secs" =~ ^[0-9]+$ ]] || fail "Duración no válida: '${secs}' (segundos; 0 = permanente)"
    create_sets
    ipset add -exist vs_ban_man "$ip" timeout "$secs"
    ok "Bloqueada ${ip} durante $([[ $secs == 0 ]] && echo 'siempre' || echo "${secs}s")."
    log "ban ${ip} ${secs}s (manual)"
}

cmd_unban() {
    require_root; require_tools
    local ip="${1:-}"
    valid_ipv4 "$ip" || fail "IP no válida (solo IPv4): '${ip}'"
    local removed=1 x
    for x in vs_ban vs_ban_ssh vs_ban_man; do ipset del "$x" "$ip" 2>/dev/null && removed=0; done
    [[ $removed == 0 ]] && ok "Desbloqueada ${ip}." || warn "${ip} no estaba bloqueada localmente."
    log "unban ${ip}"
}

cmd_bans() {
    require_root; require_tools
    echo "Bloqueos locales (con tiempo restante):"
    local pair
    for pair in "vs_ban:flood de conexiones" "vs_ban_ssh:fuerza bruta SSH" "vs_ban_man:manual"; do
        ipset list "${pair%%:*}" 2>/dev/null | awk -v why="${pair#*:}" '/^[0-9]+\./ {printf "  %-16s %-8s %s\n", $1, ($3=="" ? "∞" : $3 " s"), why}'
    done
    echo
    echo "Bloqueos recibidos del panel: $(count_set vs_feed) IP(s) y $(count_set vs_asn) rango(s) de operadores/países bloqueados"
}

cmd_white_add() {
    require_root
    local ip="${1:-}"
    valid_ipv4 "$ip" || fail "IP/CIDR no válida (solo IPv4): '${ip}'"
    mkdir -p "$(dirname "$WHITELIST_FILE")"
    grep -qxF "$ip" "$WHITELIST_FILE" 2>/dev/null || echo "$ip" >> "$WHITELIST_FILE"
    have ipset && { create_sets; ipset add -exist vs_white "$ip"; ipset del vs_ban "$ip" 2>/dev/null; ipset del vs_ban_ssh "$ip" 2>/dev/null; ipset del vs_ban_man "$ip" 2>/dev/null; }
    ok "${ip} añadida a la lista blanca."
}

cmd_white_del() {
    require_root
    local ip="${1:-}"
    [[ -n "$ip" ]] || fail "Indica una IP/CIDR."
    [[ -f "$WHITELIST_FILE" ]] && sed -i "\#^${ip//./\\.}\$#d" "$WHITELIST_FILE"
    have ipset && ipset del vs_white "$ip" 2>/dev/null
    ok "${ip} quitada de la lista blanca."
}

cmd_white_list() {
    echo "Lista blanca fija : ${STATIC_WHITELIST} ${EXTRA_WHITELIST}"
    echo "Lista blanca local:"
    if [[ -f "$WHITELIST_FILE" ]]; then grep -vE '^\s*(#|$)' "$WHITELIST_FILE" | sed 's/^/  /'; else echo "  (vacía)"; fi
}

cmd_attack() {
    require_root
    case "${1:-}" in
        on|1)  set_conf ATTACK_MODE 1 ;;
        off|0) set_conf ATTACK_MODE 0 ;;
        *) fail "Uso: antiddos-fw attack on|off" ;;
    esac
    exec "$0" apply
}

set_conf() {
    local key="$1" value="$2"
    [[ "$key" =~ ^(TCP_PORTS|UDP_PORTS|WEB_PORTS|SSH_PORT|CONN_LIMIT|SYN_RATE|SYN_BURST|UDP_RATE|UDP_BURST|ICMP_RATE|BAN_SECONDS|ATTACK_MODE|SYSCTL|PANEL_URL|PANEL_DIR|API_KEY|EXTRA_WHITELIST|NET_IFACE|BAN_MODE|EXCLUDE_PORTS)$ ]] \
        || fail "Clave desconocida: ${key}"
    [[ "$value" =~ ^[A-Za-z0-9:,./_\ -]*$ ]] || fail "Valor con caracteres no permitidos."
    mkdir -p "$(dirname "$CONF")"
    touch "$CONF"; chmod 600 "$CONF"
    if grep -qE "^${key}=" "$CONF"; then
        sed -i "s#^${key}=.*#${key}=\"${value}\"#" "$CONF"
    else
        echo "${key}=\"${value}\"" >> "$CONF"
    fi
}

cmd_set() {
    require_root
    [[ $# -ge 1 ]] || fail "Uso: antiddos-fw set CLAVE VALOR"
    set_conf "$1" "${2:-}"
    ok "$1 actualizado. Aplica los cambios con: antiddos-fw apply"
}

usage() { sed -n '3,24p' "$0" | sed 's/^# \{0,1\}//'; }

case "${1:-help}" in
    apply)     cmd_apply ;;
    remove)    cmd_remove ;;
    status)    cmd_status ;;
    top)       cmd_top ;;
    ban)       shift; cmd_ban "$@" ;;
    unban)     shift; cmd_unban "$@" ;;
    bans)      cmd_bans ;;
    white-add) shift; cmd_white_add "$@" ;;
    white-del) shift; cmd_white_del "$@" ;;
    white-list|whitelist) cmd_white_list ;;
    sync)      cmd_sync ;;
    watch)     cmd_watch ;;
    attack)    shift; cmd_attack "$@" ;;
    set)       shift; cmd_set "$@" ;;
    help|-h|--help) usage ;;
    *) usage; exit 1 ;;
esac
