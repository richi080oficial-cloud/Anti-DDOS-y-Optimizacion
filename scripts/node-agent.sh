#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SBIN_FW="/usr/local/sbin/antiddos-fw"
CONF_DIR="/etc/antiddos"
CONF="${CONF_DIR}/agent.conf"
UNIT_DIR="/etc/systemd/system"

TCP_PORTS=""; UDP_PORTS=""; WEB_PORTS=""; SSH_PORT=""; PANEL_URL=""; API_KEY=""; PANEL_LOCAL=""
NO_PROMPT=0

if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;36m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi
info() { echo -e "${BLUE}[antiddos]${NC} $*"; }
ok()   { echo -e "${GREEN}[antiddos]${NC} $*"; }
warn() { echo -e "${YELLOW}[antiddos]${NC} $*" >&2; }
fail() { echo -e "${RED}[antiddos]${NC} $*" >&2; exit 1; }

trap 'fail "El agente se detuvo en la línea $LINENO."' ERR

[[ $EUID -eq 0 ]] || fail "Ejecútalo como root: añade sudo delante del comando."

have() { command -v "$1" >/dev/null 2>&1; }

pkg_install() {
    if have apt-get; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@"
    elif have dnf; then
        dnf install -y -q "$@"
    elif have yum; then
        yum install -y -q "$@"
    else
        fail "No se pudo instalar '$*' automáticamente (ni apt, ni dnf, ni yum). Instálalo a mano."
    fi
}

ensure_deps() {
    local need=()
    have iptables || need+=(iptables)
    have ipset    || need+=(ipset)
    have curl     || need+=(curl)
    if ((${#need[@]})); then
        info "Instalando dependencias: ${need[*]}…"
        pkg_install "${need[@]}"
    fi
    have iptables && have ipset || fail "iptables/ipset siguen sin estar disponibles."
}

detect_conflicts() {
    if have csf && [[ -d /etc/csf ]]; then
        warn "Se detectó CSF/LFD: puede vaciar las reglas de iptables y quitar las de Anti-DDoS y Optimización. Mejor usa solo uno."
    fi
    if have firewall-cmd && systemctl is-active --quiet firewalld 2>/dev/null; then
        warn "firewalld está activo: convive con Anti-DDoS y Optimización (usa otras tablas), pero un 'firewall-cmd --reload' no toca las reglas raw/mangle de Anti-DDoS y Optimización."
    fi
}

ask() {
    local q="$1" def="$2" ans=""
    if [[ "$NO_PROMPT" == "1" || ! -t 0 ]]; then echo "$def"; return; fi
    read -r -p "$q [${def:-vacío}]: " ans || true
    echo "${ans:-$def}"
}

write_default_conf() {
    mkdir -p "$CONF_DIR"
    cat > "$CONF" <<EOF
# Anti-DDoS y Optimización — configuración del agente de nodo
# Cambia valores con:  antiddos fw set CLAVE VALOR   y aplica con:  antiddos fw apply
TCP_PORTS="${TCP_PORTS:-25565}"
UDP_PORTS="${UDP_PORTS}"
WEB_PORTS="${WEB_PORTS}"
SSH_PORT="${SSH_PORT:-22}"
CONN_LIMIT="30"
SYN_RATE="15"
SYN_BURST="30"
UDP_RATE="400"
UDP_BURST="800"
ICMP_RATE="5"
BAN_SECONDS="900"
ATTACK_MODE="0"
SYSCTL="1"
PANEL_URL="${PANEL_URL}"
PANEL_DIR="${PANEL_LOCAL}"
API_KEY="${API_KEY}"
EXTRA_WHITELIST=""
EOF
    chmod 600 "$CONF"
}

write_units() {
    cat > "${UNIT_DIR}/antiddos-fw.service" <<EOF
[Unit]
Description=Anti-DDoS y Optimización anti-DDoS firewall
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=${SBIN_FW} apply
ExecReload=${SBIN_FW} apply
ExecStop=${SBIN_FW} remove

[Install]
WantedBy=multi-user.target
EOF

    cat > "${UNIT_DIR}/antiddos-sync.service" <<EOF
[Unit]
Description=Anti-DDoS y Optimización: sincronizar bloqueos con el panel
After=network-online.target

[Service]
Type=oneshot
ExecStart=${SBIN_FW} sync
EOF

    cat > "${UNIT_DIR}/antiddos-live.service" <<EOF
[Unit]
Description=Anti-DDoS y Optimización: contadores en vivo (cada segundo) para la gráfica del panel
After=antiddos-fw.service

[Service]
Type=simple
ExecStart=${SBIN_FW} watch
Restart=always
RestartSec=3
Nice=10

[Install]
WantedBy=multi-user.target
EOF

    cat > "${UNIT_DIR}/antiddos-sync.timer" <<EOF
[Unit]
Description=Anti-DDoS y Optimización: sincronizar bloqueos con el panel cada 30 s

[Timer]
OnBootSec=45s
OnUnitActiveSec=30s
AccuracySec=5s

[Install]
WantedBy=timers.target
EOF
}

remove_legacy_tune() {
    systemctl disable --now antiddos-tune.service >/dev/null 2>&1 || true
    rm -f "${UNIT_DIR}/antiddos-tune.service" /usr/local/sbin/antiddos-tune \
          /etc/sysctl.d/97-antiddos-tune.conf /etc/security/limits.d/97-antiddos.conf \
          /etc/systemd/system/wings.service.d/antiddos.conf
    rmdir /etc/systemd/system/wings.service.d 2>/dev/null || true
}

do_install() {
    ensure_deps
    detect_conflicts

    [[ -f "${SOURCE_DIR}/scripts/antiddos-fw.sh" ]] || fail "Falta scripts/antiddos-fw.sh en el repositorio."

    info "Instalando el firewall en ${SBIN_FW}…"
    install -m 0755 "${SOURCE_DIR}/scripts/antiddos-fw.sh" "$SBIN_FW"
    sed -i 's/\r$//' "$SBIN_FW"
    mkdir -p "$CONF_DIR"
    tr -d '[:space:]' < "${SOURCE_DIR}/VERSION" > "${CONF_DIR}/version" 2>/dev/null || echo desconocida > "${CONF_DIR}/version"

    if [[ -z "$PANEL_LOCAL" && -z "$PANEL_URL" ]]; then
        local d
        for d in /var/www/pterodactyl /var/www/panel /var/www/html/pterodactyl /srv/pterodactyl; do
            if [[ -f "$d/artisan" && -d "$d/app/Extensions/AntiDDoS" ]]; then PANEL_LOCAL="$d"; break; fi
        done
        [[ -n "$PANEL_LOCAL" ]] && info "Panel con Anti-DDoS detectado en ${PANEL_LOCAL}: los bloqueos se sincronizan en local (sin clave)."
    fi

    if [[ ! -f "$CONF" ]]; then
        TCP_PORTS="${TCP_PORTS:-$(ask 'Puertos TCP de Minecraft a proteger (coma o rango a:b)' '25565')}"
        UDP_PORTS="${UDP_PORTS:-$(ask 'Puertos UDP (Bedrock/Geyser, vacío = ninguno)' '')}"
        check_value "puertos TCP" "$TCP_PORTS" '^[0-9:,]+$'
        check_value "puertos UDP" "$UDP_PORTS" '^[0-9:,]+$'
        write_default_conf
    else
        info "Se conserva la configuración existente en ${CONF}."
        [[ -n "$TCP_PORTS" ]] && "$SBIN_FW" set TCP_PORTS "$TCP_PORTS" >/dev/null
        [[ -n "$UDP_PORTS" ]] && "$SBIN_FW" set UDP_PORTS "$UDP_PORTS" >/dev/null
        [[ -n "$WEB_PORTS" ]] && "$SBIN_FW" set WEB_PORTS "$WEB_PORTS" >/dev/null
        [[ -n "$SSH_PORT"  ]] && "$SBIN_FW" set SSH_PORT  "$SSH_PORT"  >/dev/null
        [[ -n "$PANEL_URL" ]] && "$SBIN_FW" set PANEL_URL "$PANEL_URL" >/dev/null
        [[ -n "$PANEL_LOCAL" ]] && "$SBIN_FW" set PANEL_DIR "$PANEL_LOCAL" >/dev/null
        [[ -n "$API_KEY"   ]] && "$SBIN_FW" set API_KEY   "$API_KEY"   >/dev/null
    fi

    local ssh_detected
    ssh_detected="$(ss -Hltnp 2>/dev/null | awk '/sshd/ {n=split($4,a,":"); print a[n]; exit}' || true)"
    if [[ -n "$ssh_detected" && "$ssh_detected" != "22" ]] && grep -q '^SSH_PORT="22"' "$CONF"; then
        info "SSH escucha en el puerto ${ssh_detected}; se ajusta SSH_PORT."
        "$SBIN_FW" set SSH_PORT "$ssh_detected" >/dev/null
    fi

    remove_legacy_tune
    write_units
    systemctl daemon-reload
    systemctl enable antiddos-fw.service antiddos-sync.timer antiddos-live.service >/dev/null 2>&1 || true
    systemctl restart antiddos-fw.service
    systemctl restart antiddos-sync.timer
    systemctl restart antiddos-live.service || warn "No se pudo iniciar antiddos-live (la gráfica en vivo no funcionará)."

    ok "Agente de nodo instalado y activo."
    echo
    "$SBIN_FW" status || true
    echo
    info "Configuración: ${CONF}   ·   Comandos: antiddos fw status | top | ban IP | attack on"
    warn "Recuerda: si el panel está en OTRA máquina, añade su IP a la lista blanca: antiddos fw white-add IP_DEL_PANEL"
}

do_uninstall() {
    info "Desinstalando el agente de nodo…"
    systemctl disable --now antiddos-live.service antiddos-sync.timer antiddos-fw.service >/dev/null 2>&1 || true
    [[ -x "$SBIN_FW" ]] && "$SBIN_FW" remove || true
    rm -f "${UNIT_DIR}"/antiddos-fw.service "${UNIT_DIR}"/antiddos-sync.service \
          "${UNIT_DIR}"/antiddos-sync.timer "${UNIT_DIR}"/antiddos-live.service
    systemctl daemon-reload
    rm -f "$SBIN_FW"
    rm -rf /var/lib/antiddos
    ok "Agente de nodo eliminado. Configuración conservada en ${CONF_DIR} (bórrala si no la quieres)."
}

do_status() {
    if [[ -x "$SBIN_FW" ]]; then
        "$SBIN_FW" status
        echo
        echo "  Servicio fw     : $(systemctl is-active antiddos-fw.service 2>/dev/null || echo desconocido)"
        echo "  Sync con panel  : $(systemctl is-active antiddos-sync.timer 2>/dev/null || echo desconocido)"
        echo "  Gráfica en vivo : $(systemctl is-active antiddos-live.service 2>/dev/null || echo desconocido)"
    else
        echo "Anti-DDoS y Optimización — agente de nodo: no instalado (ejecuta: sudo antiddos node-install)"
    fi
}

ACTION="${1:-help}"
shift || true
for arg in "$@"; do
    case "$arg" in
        --tcp=*)       TCP_PORTS="${arg#*=}" ;;
        --udp=*)       UDP_PORTS="${arg#*=}" ;;
        --web=*)       WEB_PORTS="${arg#*=}" ;;
        --ssh=*)       SSH_PORT="${arg#*=}" ;;
        --panel-url=*) PANEL_URL="${arg#*=}" ;;
        --key=*)       API_KEY="${arg#*=}" ;;
        --panel-dir=*) PANEL_LOCAL="${arg#*=}" ;;
        --yes|-y|--force) NO_PROMPT=1 ;;
        *) warn "Opción desconocida ignorada: $arg" ;;
    esac
done

check_value() {
    local name="$1" value="$2" re="$3"
    [[ -z "$value" || "$value" =~ $re ]] || fail "Valor no válido para ${name}: '${value}'"
}

if [[ "$ACTION" == "install" ]]; then
    check_value "--tcp"       "$TCP_PORTS" '^[0-9:,]+$'
    check_value "--udp"       "$UDP_PORTS" '^[0-9:,]+$'
    check_value "--web"       "$WEB_PORTS" '^[0-9:,]+$'
    check_value "--ssh"       "$SSH_PORT"  '^[0-9]+$'
    check_value "--panel-url" "$PANEL_URL" '^https?://[A-Za-z0-9._:/-]+$'
    check_value "--panel-dir" "$PANEL_LOCAL" '^/[A-Za-z0-9._/-]+$'
    check_value "--key"       "$API_KEY"   '^[A-Za-z0-9._-]+$'
fi

case "$ACTION" in
    install)   do_install ;;
    uninstall) do_uninstall ;;
    status)    do_status ;;
    *) echo "Uso: node-agent.sh install|uninstall|status [--tcp=..] [--udp=..] [--web=..] [--ssh=..] [--panel-url=..] [--key=..]" ;;
esac
