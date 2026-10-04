#!/usr/bin/env bash
#
# antiddos-fw — firewall anti-DDoS de Anti-DDoS y Optimización para nodos (Wings) y panel.
# Autor: Vexa Studio · Licencia: MIT
#
# Se instala como /usr/local/sbin/antiddos-fw y se ejecuta como root.
#
#   antiddos-fw apply            Aplica (o reaplica) todas las reglas
#   antiddos-fw remove           Quita todas las reglas y los sets
#   antiddos-fw status           Estado, contadores y puertos protegidos
#   antiddos-fw top              IPs con mas conexiones a los puertos protegidos
#   antiddos-fw ban IP [segs]    Bloquea una IP (por defecto BAN_SECONDS)
#   antiddos-fw unban IP         Desbloquea una IP
#   antiddos-fw bans             Lista los bloqueos activos
#   antiddos-fw white-add CIDR   Anade a la lista blanca (persistente)
#   antiddos-fw white-del CIDR   Quita de la lista blanca
#   antiddos-fw white-list       Muestra la lista blanca
#   antiddos-fw sync             Descarga bloqueos/lista blanca del panel
#   antiddos-fw attack on|off    Modo "bajo ataque" (limites mucho mas estrictos)
#   antiddos-fw set CLAVE VALOR  Cambia un valor de /etc/antiddos/agent.conf
#
# Como funciona (de menos a mas coste de CPU):
#   1. raw/PREROUTING  -> lista blanca, bloqueos, paquetes malformados, limite
#                         de SYN por IP, limite de UDP por IP. Se ejecuta ANTES de
#                         conntrack, asi un ataque no llena la tabla de conexiones.
#   2. mangle/PREROUTING -> limite de conexiones simultaneas por IP (connlimit).
#   3. sysctl          -> SYN cookies, backlog y tiempos de conntrack.
#
# Funciona con los puertos publicados por Docker (Wings) porque raw/mangle se
# evaluan antes del DNAT de Docker: el puerto que se ve es el publicado.
#
set -uo pipefail

CONF="${ANTIDDOS_CONF:-/etc/antiddos/agent.conf}"
WHITELIST_FILE="${ANTIDDOS_WHITELIST:-/etc/antiddos/whitelist.txt}"
SYSCTL_FILE="/etc/sysctl.d/98-antiddos-shield.conf"
TAG="antiddos"

# ── Valores por defecto (se sobreescriben desde agent.conf) ───────────────────
TCP_PORTS="25565"        # Minecraft Java y proxies (coma o rangos a:b; max. 15 entradas)
UDP_PORTS=""             # Bedrock/Geyser: 19132
WEB_PORTS=""             # 80,443 — vacio = sin limite SYN en la web (ver README: Cloudflare)
SSH_PORT="22"            # vacio = sin proteccion SSH
CONN_LIMIT=30            # conexiones simultaneas por IP
SYN_RATE=15              # SYN nuevos por segundo por IP antes de bloquear
SYN_BURST=30
UDP_RATE=400             # paquetes UDP por segundo por IP
UDP_BURST=800
ICMP_RATE=5              # pings por segundo por IP
BAN_SECONDS=900          # duracion del bloqueo automatico
ATTACK_MODE=0            # 1 = limites estrictos
SYSCTL=1                 # 1 = aplicar ajustes de kernel
PANEL_URL=""             # https://panel.ejemplo.com  (para 'sync')
API_KEY=""               # clave de la API de Anti-DDoS y Optimización del panel
STATIC_WHITELIST="127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16"
EXTRA_WHITELIST=""       # IPs/CIDR adicionales separados por espacio

# shellcheck disable=SC1090
[[ -f "$CONF" ]] && . "$CONF"

if [[ "${ATTACK_MODE}" == "1" ]]; then
    CONN_LIMIT=$(( CONN_LIMIT / 3 > 5 ? CONN_LIMIT / 3 : 5 ))
    SYN_RATE=$(( SYN_RATE / 3 > 3 ? SYN_RATE / 3 : 3 ))
    SYN_BURST=$(( SYN_BURST / 3 > 6 ? SYN_BURST / 3 : 6 ))
    UDP_RATE=$(( UDP_RATE / 3 > 100 ? UDP_RATE / 3 : 100 ))
    UDP_BURST=$(( UDP_BURST / 3 > 200 ? UDP_BURST / 3 : 200 ))
    ICMP_RATE=1
    BAN_SECONDS=$(( BAN_SECONDS * 4 ))
fi

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

# ── Sets ──────────────────────────────────────────────────────────────────────
create_sets() {
    ipset create -exist vs_white      hash:net
    ipset create -exist vs_white_feed hash:net
    ipset create -exist vs_feed       hash:net
    # timeout 0 = por defecto sin caducidad; cada alta indica su propio timeout.
    ipset create -exist vs_ban        hash:ip timeout 0 maxelem 524288
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
    # Las IPs propias del servidor nunca deben bloquearse.
    local own
    for own in $(hostname -I 2>/dev/null); do
        valid_ipv4 "$own" && ipset add -exist vs_white "$own"
    done
}

# ── Reglas ────────────────────────────────────────────────────────────────────
remove_rules() {
    while ipt -t raw    -D PREROUTING -j ANTIDDOS   2>/dev/null; do :; done
    while ipt -t mangle -D PREROUTING -j ANTIDDOS_M 2>/dev/null; do :; done
    local spec
    for spec in "raw ANTIDDOS" "raw VS_SYNFLOOD" "mangle ANTIDDOS_M"; do
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
    ipt -t raw -N ANTIDDOS
    ipt -t mangle -N ANTIDDOS_M

    # Sub-cadena: registra (limitado), bloquea la IP y descarta el paquete.
    ipt -t raw -A VS_SYNFLOOD -m limit --limit 6/min --limit-burst 3 -j LOG --log-prefix "ANTIDDOS ban " --log-level 4
    ipt -t raw -A VS_SYNFLOOD -j SET --add-set vs_ban src --exist --timeout "$BAN_SECONDS"
    ipt -t raw -A VS_SYNFLOOD -j DROP

    local R=(ipt -t raw -A ANTIDDOS)

    "${R[@]}" -m set --match-set vs_white src      -j RETURN
    "${R[@]}" -m set --match-set vs_white_feed src -j RETURN
    "${R[@]}" -m set --match-set vs_feed src       -j DROP
    "${R[@]}" -m set --match-set vs_ban src        -j DROP

    # Paquetes malformados / escaneos clasicos.
    "${R[@]}" -f -j DROP
    "${R[@]}" -p tcp --tcp-flags ALL NONE        -j DROP
    "${R[@]}" -p tcp --tcp-flags ALL ALL         -j DROP
    "${R[@]}" -p tcp --tcp-flags ALL FIN,PSH,URG -j DROP
    "${R[@]}" -p tcp --tcp-flags SYN,FIN SYN,FIN -j DROP
    "${R[@]}" -p tcp --tcp-flags SYN,RST SYN,RST -j DROP
    "${R[@]}" -p tcp --tcp-flags FIN,RST FIN,RST -j DROP
    "${R[@]}" -p tcp --tcp-flags ACK,FIN FIN     -j DROP
    "${R[@]}" -p tcp --tcp-flags ACK,URG URG     -j DROP

    # Ping flood.
    "${R[@]}" -p icmp --icmp-type echo-request \
        -m hashlimit --hashlimit-name vs_icmp --hashlimit-mode srcip \
        --hashlimit-above "${ICMP_RATE}/second" --hashlimit-burst 20 -j DROP

    # SYN flood por IP hacia los puertos protegidos (bloquea al atacante).
    if [[ -n "$TCP_PORTS" ]]; then
        "${R[@]}" -p tcp --syn -m multiport --dports "$TCP_PORTS" \
            -m hashlimit --hashlimit-name vs_syn --hashlimit-mode srcip \
            --hashlimit-above "${SYN_RATE}/second" --hashlimit-burst "$SYN_BURST" \
            -j VS_SYNFLOOD
    fi

    # Fuerza bruta contra SSH.
    if [[ -n "$SSH_PORT" ]]; then
        "${R[@]}" -p tcp --syn --dport "$SSH_PORT" \
            -m hashlimit --hashlimit-name vs_ssh --hashlimit-mode srcip \
            --hashlimit-above 6/minute --hashlimit-burst 8 \
            -j VS_SYNFLOOD
    fi

    # Web del panel (opcional): solo descarta, no bloquea, por si hay proxy delante.
    if [[ -n "$WEB_PORTS" ]]; then
        "${R[@]}" -p tcp --syn -m multiport --dports "$WEB_PORTS" \
            -m hashlimit --hashlimit-name vs_web --hashlimit-mode srcip \
            --hashlimit-above 60/second --hashlimit-burst 120 -j DROP
    fi

    # UDP flood (Bedrock/Geyser): solo se descarta; las IPs de origen de un
    # flood UDP suelen estar falsificadas y bloquearlas perjudicaria a terceros.
    if [[ -n "$UDP_PORTS" ]]; then
        "${R[@]}" -p udp -m multiport --dports "$UDP_PORTS" \
            -m hashlimit --hashlimit-name vs_udp --hashlimit-mode srcip \
            --hashlimit-above "${UDP_RATE}/second" --hashlimit-burst "$UDP_BURST" -j DROP
    fi

    # Conexiones simultaneas por IP (conntrack ya esta resuelto en mangle).
    if [[ -n "$TCP_PORTS" ]]; then
        ipt -t mangle -A ANTIDDOS_M -m set --match-set vs_white src -j RETURN
        ipt -t mangle -A ANTIDDOS_M -m set --match-set vs_white_feed src -j RETURN
        ipt -t mangle -A ANTIDDOS_M -p tcp --syn -m multiport --dports "$TCP_PORTS" \
            -m connlimit --connlimit-above "$CONN_LIMIT" --connlimit-mask 32 --connlimit-saddr -j DROP
    fi

    ipt -t raw    -I PREROUTING 1 -j ANTIDDOS
    ipt -t mangle -I PREROUTING 1 -j ANTIDDOS_M
}

apply_sysctl() {
    [[ "$SYSCTL" == "1" ]] || return 0
    mkdir -p "$(dirname "$SYSCTL_FILE")"
    cat > "$SYSCTL_FILE" <<'EOF'
# Generado por Anti-DDoS y Optimización — no editar a mano (se regenera con: antiddos-fw apply)
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 8192
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
net.core.somaxconn = 4096
net.core.netdev_max_backlog = 16384
net.netfilter.nf_conntrack_max = 1048576
net.netfilter.nf_conntrack_tcp_timeout_syn_recv = 30
net.netfilter.nf_conntrack_tcp_timeout_syn_sent = 30
net.netfilter.nf_conntrack_tcp_timeout_time_wait = 30
net.netfilter.nf_conntrack_tcp_timeout_close_wait = 30
net.netfilter.nf_conntrack_tcp_timeout_fin_wait = 30
net.netfilter.nf_conntrack_udp_timeout = 20
net.netfilter.nf_conntrack_udp_timeout_stream = 60
EOF
    # -e ignora claves que el kernel no tenga (p. ej. conntrack sin cargar).
    sysctl -e -q -p "$SYSCTL_FILE" >/dev/null 2>&1 || warn "Algunos ajustes de kernel no se pudieron aplicar."
    # Tabla de hash de conntrack acorde al maximo (mejora el rendimiento bajo ataque).
    if [[ -w /sys/module/nf_conntrack/parameters/hashsize ]]; then
        echo 262144 > /sys/module/nf_conntrack/parameters/hashsize 2>/dev/null || true
    fi
}

# ── Sincronizacion con el panel ───────────────────────────────────────────────
sync_feed() {
    [[ -n "$PANEL_URL" && -n "$API_KEY" ]] || return 0
    have curl || { warn "curl no está instalado; no se puede sincronizar con el panel."; return 1; }

    local body
    if ! body="$(curl -fsS --max-time 20 -H "X-Antiddos-Key: ${API_KEY}" "${PANEL_URL%/}/api/antiddos/feed" 2>&1)"; then
        warn "No se pudo descargar la lista del panel: ${body:0:200}"
        log "feed: fallo de descarga"
        return 1
    fi

    ipset create -exist vs_feed_tmp hash:net
    ipset create -exist vs_white_feed_tmp hash:net
    ipset flush vs_feed_tmp
    ipset flush vs_white_feed_tmp

    local kind addr bans=0 whites=0
    while read -r kind addr _; do
        valid_ipv4 "${addr:-x}" || continue
        case "$kind" in
            B) ipset add -exist vs_feed_tmp "$addr"; bans=$((bans + 1)) ;;
            W) ipset add -exist vs_white_feed_tmp "$addr"; whites=$((whites + 1)) ;;
        esac
    done <<<"$body"

    ipset swap vs_feed_tmp vs_feed && ipset destroy vs_feed_tmp
    ipset swap vs_white_feed_tmp vs_white_feed && ipset destroy vs_white_feed_tmp
    log "feed: ${bans} bloqueos, ${whites} en lista blanca"
    return 0
}

# ── Comandos ──────────────────────────────────────────────────────────────────
cmd_apply() {
    require_root; require_tools
    create_sets
    load_whitelist
    sync_feed || true
    apply_rules
    apply_sysctl
    ok "Escudo activo. TCP: ${TCP_PORTS:-—}  UDP: ${UDP_PORTS:-—}  límite conexiones/IP: ${CONN_LIMIT}  modo ataque: $([[ $ATTACK_MODE == 1 ]] && echo SÍ || echo no)"
    log "apply (tcp=${TCP_PORTS} udp=${UDP_PORTS} attack=${ATTACK_MODE})"
}

cmd_remove() {
    require_root
    have iptables && remove_rules
    if have ipset; then
        local s
        for s in vs_white vs_white_feed vs_feed vs_ban vs_feed_tmp vs_white_feed_tmp; do
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
    echo "  Límites         : conn=${CONN_LIMIT}/IP  syn=${SYN_RATE}/s (ráfaga ${SYN_BURST})  udp=${UDP_RATE}/s  ban=${BAN_SECONDS}s"
    echo "  Panel (sync)    : ${PANEL_URL:-no configurado}"
    if have ipset; then
        local s n
        for s in vs_ban vs_feed vs_white vs_white_feed; do
            n="$(ipset list "$s" 2>/dev/null | awk '/^Number of entries:/ {print $4}')"
            printf '  %-15s : %s entradas\n' "$s" "${n:-—}"
        done
    fi
    if have iptables && ipt -t raw -L ANTIDDOS -n >/dev/null 2>&1; then
        echo
        echo "Paquetes descartados (contadores desde el último apply):"
        ipt -t raw -L ANTIDDOS -v -n 2>/dev/null | awk 'NR>2 && $1+0 > 0 {print "  " $0}'
        ipt -t mangle -L ANTIDDOS_M -v -n 2>/dev/null | awk 'NR>2 && $1+0 > 0 {print "  " $0}'
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
    ipset add -exist vs_ban "$ip" timeout "$secs"
    ok "Bloqueada ${ip} durante $([[ $secs == 0 ]] && echo 'siempre' || echo "${secs}s")."
    log "ban ${ip} ${secs}s (manual)"
}

cmd_unban() {
    require_root; require_tools
    local ip="${1:-}"
    valid_ipv4 "$ip" || fail "IP no válida (solo IPv4): '${ip}'"
    ipset del vs_ban "$ip" 2>/dev/null && ok "Desbloqueada ${ip}." || warn "${ip} no estaba bloqueada localmente."
    log "unban ${ip}"
}

cmd_bans() {
    require_root; require_tools
    echo "Bloqueos locales (con tiempo restante):"
    ipset list vs_ban 2>/dev/null | awk '/^[0-9]+\./ {printf "  %-16s %s s\n", $1, ($3=="" ? "∞" : $3)}'
    echo
    echo "Bloqueos recibidos del panel: $(ipset list vs_feed 2>/dev/null | awk '/^Number of entries:/ {print $4}')"
}

cmd_white_add() {
    require_root
    local ip="${1:-}"
    valid_ipv4 "$ip" || fail "IP/CIDR no válida (solo IPv4): '${ip}'"
    mkdir -p "$(dirname "$WHITELIST_FILE")"
    grep -qxF "$ip" "$WHITELIST_FILE" 2>/dev/null || echo "$ip" >> "$WHITELIST_FILE"
    have ipset && { create_sets; ipset add -exist vs_white "$ip"; ipset del vs_ban "$ip" 2>/dev/null; }
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
    # Se relee la configuracion en un proceso nuevo para que apliquen los limites del modo elegido.
    exec "$0" apply
}

set_conf() {
    local key="$1" value="$2"
    [[ "$key" =~ ^(TCP_PORTS|UDP_PORTS|WEB_PORTS|SSH_PORT|CONN_LIMIT|SYN_RATE|SYN_BURST|UDP_RATE|UDP_BURST|ICMP_RATE|BAN_SECONDS|ATTACK_MODE|SYSCTL|PANEL_URL|API_KEY|EXTRA_WHITELIST)$ ]] \
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
    sync)      require_root; require_tools; create_sets; sync_feed ;;
    attack)    shift; cmd_attack "$@" ;;
    set)       shift; cmd_set "$@" ;;
    help|-h|--help) usage ;;
    *) usage; exit 1 ;;
esac
