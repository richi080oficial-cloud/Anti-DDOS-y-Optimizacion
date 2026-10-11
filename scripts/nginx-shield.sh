#!/usr/bin/env bash
set -uo pipefail

CONF_FILE="/etc/nginx/conf.d/antiddos.conf"
RATE=20; BURST=100; CONN=50; FORCE=0

if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;36m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi
info() { echo -e "${BLUE}[antiddos]${NC} $*"; }
ok()   { echo -e "${GREEN}[antiddos]${NC} $*"; }
warn() { echo -e "${YELLOW}[antiddos]${NC} $*" >&2; }
fail() { echo -e "${RED}[antiddos]${NC} $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Ejecútalo como root."
command -v nginx >/dev/null 2>&1 || fail "nginx no está instalado en esta máquina."

ACTION="${1:-status}"
shift || true
for arg in "$@"; do
    case "$arg" in
        --rate=*)  RATE="${arg#*=}" ;;
        --burst=*) BURST="${arg#*=}" ;;
        --conn=*)  CONN="${arg#*=}" ;;
        --force|-y) FORCE=1 ;;
        *) warn "Opción desconocida ignorada: $arg" ;;
    esac
done

for v in "$RATE" "$BURST" "$CONN"; do
    [[ "$v" =~ ^[0-9]+$ && "$v" -gt 0 ]] || fail "Los valores --rate/--burst/--conn deben ser enteros positivos."
done

reload_nginx() {
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx 2>/dev/null || nginx -s reload
        return 0
    fi
    return 1
}

case "$ACTION" in
    on)
        if ! nginx -T 2>/dev/null | grep -q 'set_real_ip_from' && [[ "$FORCE" != "1" ]]; then
            if grep -qE '^TRUSTED_PROXIES=.+' /var/www/pterodactyl/.env 2>/dev/null; then
                warn "El panel tiene TRUSTED_PROXIES (proxy/Cloudflare delante) pero nginx no tiene set_real_ip_from."
                warn "Con eso nginx vería una sola IP para todos los usuarios y los limitaría a la vez."
                fail "Configura real_ip en nginx o repite con --force si sabes lo que haces."
            fi
        fi

        cat > "$CONF_FILE" <<EOF
# Generado por Anti-DDoS y Optimización — no editar a mano (cambia valores con: antiddos nginx-on --rate=.. --burst=.. --conn=..)
limit_req_zone  \$binary_remote_addr zone=antiddos_req:20m  rate=${RATE}r/s;
limit_conn_zone \$binary_remote_addr zone=antiddos_conn:10m;

limit_req        zone=antiddos_req burst=${BURST} nodelay;
limit_req_status 429;
limit_req_log_level warn;

limit_conn        antiddos_conn ${CONN};
limit_conn_status 429;
limit_conn_log_level warn;
EOF
        if reload_nginx; then
            ok "Límites de nginx activos: ${RATE} peticiones/s por IP (ráfaga ${BURST}), ${CONN} conexiones simultáneas."
        else
            rm -f "$CONF_FILE"
            reload_nginx || true
            fail "nginx -t falló con la nueva configuración; se ha deshecho el cambio. Revisa: nginx -t"
        fi
        ;;
    off)
        if [[ -f "$CONF_FILE" ]]; then
            rm -f "$CONF_FILE"
            reload_nginx && ok "Límites de nginx desactivados." || warn "Archivo eliminado, pero nginx -t falla por otro motivo."
        else
            info "Los límites de nginx no estaban activos."
        fi
        ;;
    status)
        if [[ -f "$CONF_FILE" ]]; then
            echo "Límites de nginx: ACTIVOS"
            grep -E 'rate=|burst=|limit_conn ' "$CONF_FILE" | sed 's/^/  /'
        else
            echo "Límites de nginx: inactivos (actívalos con: antiddos nginx-on)"
        fi
        ;;
    *) echo "Uso: nginx-shield.sh on|off|status [--rate=20] [--burst=100] [--conn=50] [--force]" ;;
esac
