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
#   1. raw/PREROUTING  -> lista blanca, bloqueos (IPs, operadores y paises del panel),
#                         paquetes malformados, amplificacion UDP, limite de SYN por IP
#                         (y por /24 en modo ataque), limite de UDP por IP. Se ejecuta
#                         ANTES de conntrack, asi un ataque no llena la tabla de conexiones.
#   2. mangle/PREROUTING -> TCP fuera de conexion (floods de ACK) y limite de conexiones
#                         simultaneas por IP (connlimit).
#   3. sysctl          -> SYN cookies, colas, backlog y tiempos de conntrack.
#
# Funciona con los puertos publicados por Docker (Wings) porque raw/mangle se
# evaluan antes del DNAT de Docker: el puerto que se ve es el publicado.
#
set -uo pipefail

CONF="${ANTIDDOS_CONF:-/etc/antiddos/agent.conf}"
WHITELIST_FILE="${ANTIDDOS_WHITELIST:-/etc/antiddos/whitelist.txt}"
SYSCTL_FILE="/etc/sysctl.d/98-antiddos-shield.conf"
TAG="antiddos"

# ── Configuracion ─────────────────────────────────────────────────────────────
# Valores por defecto; agent.conf los sobreescribe y el panel puede cambiarlos
# (lineas "C CLAVE VALOR" del feed, ver sync_feed).
load_conf() {
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
    PANEL_DIR=""             # ruta del panel si esta en ESTA maquina (sync local, sin clave)
    API_KEY=""               # clave de la API del panel
    STATIC_WHITELIST="127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16"
    EXTRA_WHITELIST=""       # IPs/CIDR adicionales separados por espacio
    NET_IFACE=""             # interfaz publica para RX/TX (vacio = la de la ruta por defecto)
    BAN_MODE="balanced"      # monitor = nunca bloquea IPs (solo frena el exceso) | balanced = bloquea solo ante floods claros | strict = bloquea al primer exceso

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

# ── Sets ──────────────────────────────────────────────────────────────────────
create_sets() {
    ipset create -exist vs_white      hash:net
    ipset create -exist vs_white_feed hash:net
    ipset create -exist vs_feed       hash:net
    # timeout 0 = por defecto sin caducidad; cada alta indica su propio timeout.
    ipset create -exist vs_ban        hash:ip timeout 0 maxelem 524288
    # Un set por motivo, para poder decir exactamente por que esta bloqueada cada IP.
    ipset create -exist vs_ban_ssh    hash:ip timeout 0 maxelem 65536
    ipset create -exist vs_ban_man    hash:ip timeout 0 maxelem 65536
    # Rangos de operadores (ASN) y paises bloqueados desde el panel (lineas "A" del feed).
    ipset create -exist vs_asn        hash:net maxelem "$NET_MAXELEM"
}

# Tamano de los sets que se rellenan desde el panel.
FEED_MAXELEM=262144     # IPs bloqueadas y lista blanca
NET_MAXELEM=1048576     # rangos de operadores/paises (un pais grande son decenas de miles)

# Tablas de los limites por IP (hashlimit). Por defecto el kernel solo guarda unas
# miles de IPs: en un flood con muchas IPs distintas la tabla se llena y el kernel
# descarta tambien a los jugadores nuevos. Con estas tablas aguanta muchas mas.
HL_BIG=(--hashlimit-htable-size 32768 --hashlimit-htable-max 262144)
HL_SMALL=(--hashlimit-htable-size 4096 --hashlimit-htable-max 65536)

# Puertos de ORIGEN de los ataques de amplificacion/reflexion por UDP (DNS, NTP,
# memcached, SSDP, CLDAP, chargen, SNMP, NetBIOS, RIP, ARMS, WS-Discovery, mDNS...).
# Un jugador nunca envia desde estos puertos: lo que llega de ellos a tus puertos
# de Minecraft son respuestas falsas de un ataque. (max. 15 entradas)
AMP_SPORTS="17,19,53,111,123,137,161,389,520,1900,3283,3702,5353,11211"

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
    # Cadenas vacias: solo sirven para contar paquetes (graficas del panel).
    ipt -t raw -N ANTIDDOS_CTCP
    ipt -t raw -N ANTIDDOS_CUDP
    ipt -t raw -N ANTIDDOS_CSYN
    ipt -t raw -N ANTIDDOS
    ipt -t raw -N ANTIDDOS_AMP
    ipt -t mangle -N ANTIDDOS_M
    ipt -t mangle -N ANTIDDOS_M_INV
    ipt -t mangle -A ANTIDDOS_M_INV -j DROP

    # Una cadena por MOTIVO de descarte (cada una solo hace DROP): asi el panel
    # puede decir exactamente por que se frena el trafico (grafica «Motivos»).
    local reason
    for reason in BAN MAL ICMP UDP WEB SLIM; do
        ipt -t raw -N "ANTIDDOS_D_${reason}"
        ipt -t raw -A "ANTIDDOS_D_${reason}" -j DROP
    done

    # Sub-cadena: registra (limitado), bloquea la IP y descarta el paquete.
    ipt -t raw -A VS_SYNFLOOD -m limit --limit 6/min --limit-burst 3 -j LOG --log-prefix "ANTIDDOS ban syn " --log-level 4
    ipt -t raw -A VS_SYNFLOOD -j SET --add-set vs_ban src --exist --timeout "$BAN_SECONDS"
    ipt -t raw -A VS_SYNFLOOD -j DROP
    # Fuerza bruta SSH: set aparte (motivo distinto).
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

    # Paquetes malformados / escaneos clasicos.
    "${R[@]}" -f -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ALL NONE        -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ALL ALL         -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ALL FIN,PSH,URG -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags SYN,FIN SYN,FIN -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags SYN,RST SYN,RST -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags FIN,RST FIN,RST -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ACK,FIN FIN     -j ANTIDDOS_D_MAL
    "${R[@]}" -p tcp --tcp-flags ACK,URG URG     -j ANTIDDOS_D_MAL

    # Ping flood.
    "${R[@]}" -p icmp --icmp-type echo-request \
        -m hashlimit --hashlimit-name vs_icmp --hashlimit-mode srcip "${HL_SMALL[@]}" \
        --hashlimit-above "${ICMP_RATE}/second" --hashlimit-burst 20 -j ANTIDDOS_D_ICMP

    # Contadores del trafico externo hacia los puertos protegidos (lo de la lista
    # blanca ya salio con RETURN y no se cuenta).
    if [[ -n "$TCP_PORTS" ]]; then
        "${R[@]}" -p tcp -m multiport --dports "$TCP_PORTS" -j ANTIDDOS_CTCP
        "${R[@]}" -p tcp --syn -m multiport --dports "$TCP_PORTS" -j ANTIDDOS_CSYN
    fi
    if [[ -n "$UDP_PORTS" ]]; then
        "${R[@]}" -p udp -m multiport --dports "$UDP_PORTS" -j ANTIDDOS_CUDP
    fi

    # Amplificacion/reflexion UDP hacia los puertos protegidos (incluido el «query»
    # UDP de los servidores Java, que usa el mismo puerto que el juego).
    ipt -t raw -A ANTIDDOS_AMP -p udp -m multiport --sports "$AMP_SPORTS" -j ANTIDDOS_D_UDP
    if [[ -n "$UDP_PORTS" ]]; then
        "${R[@]}" -p udp -m multiport --dports "$UDP_PORTS" -j ANTIDDOS_AMP
    fi
    if [[ -n "$TCP_PORTS" ]]; then
        "${R[@]}" -p udp -m multiport --dports "$TCP_PORTS" -j ANTIDDOS_AMP
    fi

    # Politica de bloqueo (BAN_MODE):
    #   monitor  -> NUNCA se bloquea una IP: solo se frena el exceso de paquetes.
    #   balanced -> el exceso se frena y solo se bloquea ante un flood claro (3 veces el limite).
    #   strict   -> se bloquea al primer exceso del limite.
    local ban_rate="$SYN_RATE" ban_burst="$SYN_BURST" ssh_rate="6/minute" ssh_burst=8
    if [[ "$BAN_MODE" != "strict" ]]; then
        ban_rate=$(( SYN_RATE * 3 )); ban_burst=$(( SYN_BURST * 3 )); ssh_rate="20/minute"; ssh_burst=20
    fi

    # Conexiones nuevas por IP hacia los puertos protegidos.
    if [[ -n "$TCP_PORTS" ]]; then
        if [[ "$BAN_MODE" != "monitor" ]]; then
            "${R[@]}" -p tcp --syn -m multiport --dports "$TCP_PORTS" \
                -m hashlimit --hashlimit-name vs_synban --hashlimit-mode srcip "${HL_BIG[@]}" \
                --hashlimit-above "${ban_rate}/second" --hashlimit-burst "$ban_burst" \
                -j VS_SYNFLOOD
        fi
        if [[ "$BAN_MODE" != "strict" ]]; then
            "${R[@]}" -p tcp --syn -m multiport --dports "$TCP_PORTS" \
                -m hashlimit --hashlimit-name vs_syn --hashlimit-mode srcip "${HL_BIG[@]}" \
                --hashlimit-above "${SYN_RATE}/second" --hashlimit-burst "$SYN_BURST" \
                -j ANTIDDOS_D_SLIM
        fi
        # Modo «bajo ataque»: limite tambien por red /24. Frena las botnets que reparten
        # el ataque entre muchas IPs del mismo operador (cada una por debajo del limite
        # por IP). Solo frena, no bloquea: margen de 4 veces el limite por IP.
        if [[ "${ATTACK_MODE}" == "1" ]]; then
            "${R[@]}" -p tcp --syn -m multiport --dports "$TCP_PORTS" \
                -m hashlimit --hashlimit-name vs_syn24 --hashlimit-mode srcip --hashlimit-srcmask 24 "${HL_BIG[@]}" \
                --hashlimit-above "$(( SYN_RATE * 4 ))/second" --hashlimit-burst "$(( SYN_BURST * 4 ))" \
                -j ANTIDDOS_D_SLIM
        fi
    fi

    # Fuerza bruta contra SSH.
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

    # Web del panel (opcional): solo descarta, no bloquea, por si hay proxy delante.
    if [[ -n "$WEB_PORTS" ]]; then
        "${R[@]}" -p tcp --syn -m multiport --dports "$WEB_PORTS" \
            -m hashlimit --hashlimit-name vs_web --hashlimit-mode srcip "${HL_SMALL[@]}" \
            --hashlimit-above 60/second --hashlimit-burst 120 -j ANTIDDOS_D_WEB
    fi

    # UDP flood (Bedrock/Geyser): solo se descarta; las IPs de origen de un
    # flood UDP suelen estar falsificadas y bloquearlas perjudicaria a terceros.
    if [[ -n "$UDP_PORTS" ]]; then
        "${R[@]}" -p udp -m multiport --dports "$UDP_PORTS" \
            -m hashlimit --hashlimit-name vs_udp --hashlimit-mode srcip "${HL_BIG[@]}" \
            --hashlimit-above "${UDP_RATE}/second" --hashlimit-burst "$UDP_BURST" -j ANTIDDOS_D_UDP
    fi

    # Conexiones simultaneas por IP (conntrack ya esta resuelto en mangle).
    if [[ -n "$TCP_PORTS" ]]; then
        ipt -t mangle -A ANTIDDOS_M -m set --match-set vs_white src -j RETURN
        ipt -t mangle -A ANTIDDOS_M -m set --match-set vs_white_feed src -j RETURN
        # Paquetes TCP que no pertenecen a ninguna conexion (floods de ACK/RST/PSH):
        # con nf_conntrack_tcp_loose=0 (ver sysctl) conntrack los marca INVALID y no
        # llegan a ocupar la tabla de conexiones ni a Docker.
        ipt -t mangle -A ANTIDDOS_M -p tcp -m multiport --dports "$TCP_PORTS" \
            -m conntrack --ctstate INVALID -j ANTIDDOS_M_INV
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
    # -e ignora claves que el kernel no tenga (p. ej. conntrack sin cargar).
    sysctl -e -q -p "$SYSCTL_FILE" >/dev/null 2>&1 || warn "Algunos ajustes de kernel no se pudieron aplicar."
    # Tabla de hash de conntrack acorde al maximo (mejora el rendimiento bajo ataque).
    if [[ -w /sys/module/nf_conntrack/parameters/hashsize ]]; then
        echo 262144 > /sys/module/nf_conntrack/parameters/hashsize 2>/dev/null || true
    fi
}

# ── Sincronizacion con el panel ───────────────────────────────────────────────
# Ejecuta artisan del panel local como el usuario web (evita dejar cache de root).
panel_artisan() {
    local owner; owner="$(stat -c '%U' "$PANEL_DIR/public" 2>/dev/null || echo root)"
    if [[ "$owner" != "root" ]] && have runuser; then
        ( cd "$PANEL_DIR" && runuser -u "$owner" -- php artisan antiddos:manage "$@" )
    else
        ( cd "$PANEL_DIR" && php artisan antiddos:manage "$@" )
    fi
}

panel_is_local() { [[ -n "$PANEL_DIR" && -f "$PANEL_DIR/artisan" ]] && have php; }

# Descarga la lista del panel. Si el panel esta en esta misma maquina
# (PANEL_DIR) se lee directamente con artisan: sin red y sin clave.
fetch_feed() {
    if panel_is_local; then
        panel_artisan feed 2>&1
        return
    fi
    have curl || { echo "curl no esta instalado"; return 1; }
    curl -fsS --max-time 20 -H "X-Antiddos-Key: ${API_KEY}" "${PANEL_URL%/}/api/antiddos/feed" 2>&1
}

conf_get() { grep -m1 -E "^$1=" "$CONF" 2>/dev/null | cut -d= -f2- | tr -d '"'; }

# Aplica un ajuste enviado por el panel si es valido y distinto del actual.
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

sync_feed() {
    [[ -n "$PANEL_DIR" || ( -n "$PANEL_URL" && -n "$API_KEY" ) ]] || return 0

    local body
    if ! body="$(fetch_feed)"; then
        warn "No se pudo obtener la lista del panel: ${body:0:200}"
        log "feed: fallo de descarga"
        return 1
    fi

    # Una respuesta que no es un feed (p. ej. un error de PHP) no debe vaciar los bloqueos.
    if [[ -n "$body" ]] && ! grep -qE '^[BWAC] ' <<<"$body"; then
        warn "Respuesta del panel no válida: ${body:0:200}"
        log "feed: respuesta no valida"
        return 1
    fi

    # Todo se carga de golpe con «ipset restore» (un solo proceso aunque haya miles de
    # rangos) en sets temporales que luego se intercambian: el firewall nunca se queda
    # ni un instante sin bloqueos.
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
                # Rango de un operador/pais: nunca mas grande que una /8.
                A) valid_ipv4 "${addr:-x}" && [[ "$addr" == */* && "${addr#*/}" -ge 8 ]] && { echo "add vs_asn_tmp ${addr}"; nets=$((nets + 1)); } ;;
                C) apply_remote_setting "${addr:-}" "${val:-}" >&2 ;;
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
    return 0
}

# ── Informe de estado al panel ────────────────────────────────────────────────
json_arr() { local out="" x; for x in "$@"; do out+="${out:+,}\"${x}\""; done; echo "[${out}]"; }

count_bans() {
    local n=0 x
    for x in vs_ban vs_ban_ssh vs_ban_man; do n=$(( n + $(count_set "$x" || echo 0) + 0 )); done
    echo "$n"
}

# Bloqueos locales activos con motivo y segundos restantes (JSON, maximo 30).
fw_bans_json() {
    local pair set why
    {
        for pair in "vs_ban:syn" "vs_ban_ssh:ssh" "vs_ban_man:manual"; do
            set="${pair%%:*}"; why="${pair##*:}"
            ipset list "$set" 2>/dev/null | awk -v why="$why" '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/ { print $1, ($3 == "" ? 0 : $3), why }'
        done
    } | head -30 | awk 'BEGIN { printf "[" } { printf "%s{\"ip\":\"%s\",\"left\":%d,\"why\":\"%s\"}", (NR > 1 ? "," : ""), $1, $2, $3 } END { print "]" }'
}

count_set() { ipset list "$1" 2>/dev/null | awk '/^Number of entries:/ {print $4}' | head -1; }

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
    ips="$(json_arr $(hostname -I 2>/dev/null))"
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

# ── Modo «en vivo»: contadores cada segundo para la grafica del panel ─────────────
LIVE_DIR="/var/lib/antiddos"
LIVE_RING="${LIVE_DIR}/live.ring"
LIVE_JSON="${LIVE_DIR}/live.json"
LIVE_EVERY=1
LIVE_KEEP=300        # 300 muestras x 1 s = 5 minutos

# Una sola lectura de raw y otra de mangle. Imprime 13 numeros:
#   tcp udp syn  tcp_bytes udp_bytes  d_ban d_mal d_icmp d_syn d_udp d_conn d_web  drop_bytes
# (paquetes recibidos hacia los puertos protegidos y paquetes frenados por motivo).
read_counters() {
    local raw mangle dm
    raw="$(ipt -t raw -L -v -n -x 2>/dev/null)"
    mangle="$(ipt -t mangle -L -v -n -x 2>/dev/null)"
    # mangle: ANTIDDOS_M = limite de conexiones simultaneas; ANTIDDOS_M_INV = TCP fuera de conexion (cuenta como malformado).
    dm="$(awk '/^Chain / { chain = $2; next }
               $3 == "DROP" && chain == "ANTIDDOS_M"     { p += $1; b += $2 }
               $3 == "DROP" && chain == "ANTIDDOS_M_INV" { ip += $1; b += $2 }
               END { printf "%d %d %d", p, b, ip }' <<<"$mangle")"
    awk -v dm="$dm" '
        BEGIN { split(dm, m, " "); dconn = m[1] + 0; dbytes = m[2] + 0; dmal = m[3] + 0 }
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
        }
        END { printf "%d %d %d %d %d %d %d %d %d %d %d %d %d\n", tcp, udp, syn, tcpb, udpb, dban, dmal, dicmp, dsyn, dudp, dconn, dweb, dbytes }' <<<"$raw"
}

# Interfaz publica: la de la ruta por defecto (o NET_IFACE si se fuerza en agent.conf).
net_iface() {
    if [[ -n "${NET_IFACE:-}" ]]; then printf '%s' "$NET_IFACE"; return; fi
    local dev
    dev="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit }}')"
    [[ -z "$dev" ]] && dev="$(awk -F'[: ]+' 'NR > 2 && $2 != "lo" && $2 !~ /^(docker|veth|br-|pterodactyl)/ { print $2; exit }' /proc/net/dev 2>/dev/null)"
    printf '%s' "$dev"
}

# Contadores de la tarjeta de red (como la grafica de OVH): "rx_bytes rx_pkts tx_bytes tx_pkts".
read_net() {
    local dev="${1:-$(net_iface)}"
    [[ -n "$dev" ]] || { echo "0 0 0 0"; return; }
    awk -v dev="$dev" '{ line = $0; sub(/^[ \t]+/, "", line); n = index(line, ":"); if (n == 0) next
            name = substr(line, 1, n - 1); if (name != dev) next
            split(substr(line, n + 1), f, " ")
            printf "%d %d %d %d\n", f[1], f[2], f[9], f[10]; found = 1 }
        END { if (!found) print "0 0 0 0" }' /proc/net/dev 2>/dev/null
}

# Hora con milisegundos (mas precision para calcular velocidades).
now_ms() {
    local t="${EPOCHREALTIME:-}"
    if [[ -n "$t" ]]; then t="${t/,/.}"; printf '%s' "${t%???}"; else date +%s.%3N 2>/dev/null || date +%s; fi
}

# Las 10 IPs externas con mas conexiones hacia los puertos protegidos (JSON).
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

# Bucle del servicio antiddos-live: lee los contadores cada segundo, guarda los ultimos
# 5 minutos en un archivo que el panel lee directamente (panel en la misma maquina)
# y, si el panel esta en otra, envia cada muestra por HTTPS.
cmd_watch() {
    require_root; require_tools
    mkdir -p "$LIVE_DIR"; chmod 755 "$LIVE_DIR"
    : > "$LIVE_RING"
    info "Modo en vivo activo: contadores cada ${LIVE_EVERY} s (${LIVE_JSON})."
    local loops=0

    local ts line net host bans_now dev
    host="$(hostname)"
    dev="$(net_iface)"

    while true; do
        load_conf
        ts="$(now_ms)"
        line="$(read_counters)"
        net="$(read_net "$dev")"
        (( loops % 60 == 0 )) && dev="$(net_iface)"
        bans_now="$(count_bans)"
        echo "${ts} ${line} ${net} ${bans_now}" >> "$LIVE_RING"

        # El archivo se recorta cada 60 vueltas (no en cada una, para gastar poco);
        # el JSON solo incluye siempre las ultimas LIVE_KEEP muestras.
        loops=$((loops + 1))
        if (( loops % 60 == 0 )); then
            tail -n "$LIVE_KEEP" "$LIVE_RING" > "${LIVE_RING}.tmp" && mv -f "${LIVE_RING}.tmp" "$LIVE_RING"
        fi

        tail -n "$LIVE_KEEP" "$LIVE_RING" | awk -v host="$host" '
            BEGIN { printf "{\"host\":\"%s\",\"samples\":[", host }
            { printf "%s{\"t\":%s,\"tcp\":%s,\"udp\":%s,\"syn\":%s,\"tcp_b\":%s,\"udp_b\":%s,\"d_ban\":%s,\"d_mal\":%s,\"d_icmp\":%s,\"d_syn\":%s,\"d_udp\":%s,\"d_conn\":%s,\"d_web\":%s,\"d_bytes\":%s,\"rx_b\":%s,\"rx_p\":%s,\"tx_b\":%s,\"tx_p\":%s,\"bans\":%s}", (NR > 1 ? "," : ""), $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19 }
            END { print "]}" }' > "${LIVE_JSON}.tmp" && chmod 644 "${LIVE_JSON}.tmp" && mv -f "${LIVE_JSON}.tmp" "$LIVE_JSON"

        # Panel en otra maquina: se envia la muestra (en segundo plano, sin frenar el bucle).
        if ! panel_is_local && [[ -n "$PANEL_URL" && -n "$API_KEY" ]] && have curl; then
            # shellcheck disable=SC2086
            set -- $net; local rxb=$1 rxp=$2 txb=$3 txp=$4
            # shellcheck disable=SC2086
            set -- $line
            ( curl -fsS --max-time 2 -X POST -H "X-Antiddos-Key: ${API_KEY}" -H "Content-Type: application/json" \
                --data "{\"host\":\"${host}\",\"t\":${ts},\"tcp\":$1,\"udp\":$2,\"syn\":$3,\"tcp_b\":$4,\"udp_b\":$5,\"d_ban\":$6,\"d_mal\":$7,\"d_icmp\":$8,\"d_syn\":$9,\"d_udp\":${10},\"d_conn\":${11},\"d_web\":${12},\"d_bytes\":${13},\"rx_b\":${rxb},\"rx_p\":${rxp},\"tx_b\":${txb},\"tx_p\":${txp},\"bans\":${bans_now}}" \
                "${PANEL_URL%/}/api/antiddos/live" >/dev/null 2>&1 & )
        fi

        sleep "$LIVE_EVERY"
    done
}

# Sincronizacion periodica (timer de systemd): descarga bloqueos y ajustes del
# panel; si los ajustes cambiaron, reconstruye las reglas.
cmd_sync() {
    require_root; require_tools
    create_sets
    sync_feed || true
    if [[ "$CONF_CHANGED" == "1" ]]; then
        load_conf
        load_whitelist
        apply_rules
        apply_sysctl
        log "reglas reaplicadas por cambios del panel (tcp=${TCP_PORTS} udp=${UDP_PORTS} attack=${ATTACK_MODE})"
    fi
    send_heartbeat
}

# ── Comandos ──────────────────────────────────────────────────────────────────
cmd_apply() {
    require_root; require_tools
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
    have iptables && remove_rules
    if have ipset; then
        local s
        for s in vs_white vs_white_feed vs_feed vs_asn vs_ban vs_ban_ssh vs_ban_man vs_feed_tmp vs_white_feed_tmp vs_asn_tmp; do
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
    # Se relee la configuracion en un proceso nuevo para que apliquen los limites del modo elegido.
    exec "$0" apply
}

set_conf() {
    local key="$1" value="$2"
    [[ "$key" =~ ^(TCP_PORTS|UDP_PORTS|WEB_PORTS|SSH_PORT|CONN_LIMIT|SYN_RATE|SYN_BURST|UDP_RATE|UDP_BURST|ICMP_RATE|BAN_SECONDS|ATTACK_MODE|SYSCTL|PANEL_URL|PANEL_DIR|API_KEY|EXTRA_WHITELIST|NET_IFACE|BAN_MODE)$ ]] \
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
