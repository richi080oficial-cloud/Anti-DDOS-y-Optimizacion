#!/usr/bin/env bash
set -Eeuo pipefail

REPO_BRANCH="${REPO_BRANCH:-main}"
SRC_DIR="${SRC_DIR:-/opt/antiddos}"
if [[ -z "${REPO_URL:-}" && -z "${ANTIDDOS_REPO:-}" && -d "$SRC_DIR/.git" ]]; then
    REPO_URL="$(git -C "$SRC_DIR" remote get-url origin 2>/dev/null || true)"
fi
REPO_URL="${REPO_URL:-${ANTIDDOS_REPO:-https://github.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion.git}}"
INSTALLER_REL="scripts/addon-install.sh"
NODE_REL="scripts/node-agent.sh"
STATE_DIR="/usr/local/share/antiddos"
STATE_FILE="${STATE_DIR}/state.env"
CLI_PATH="/usr/local/bin/antiddos"
CRON_MARKER="antiddos-autoupdate"
LOG_FILE="/var/log/antiddos-update.log"
TARGET_REL="app/Extensions/AntiDDoS"
FW_BIN="/usr/local/sbin/antiddos-fw"

PANEL_DIR="${PANEL_DIR:-}"
WEB_USER="${WEB_USER:-}"
FORCE="${FORCE:-0}"
DROP_DATA="${DROP_DATA:-0}"
API_KEY=""
NODE_PANEL_URL=""
PASSTHRU=()

if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;36m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi

info() { echo -e "${BLUE}[antiddos]${NC} $*"; }
ok()   { echo -e "${GREEN}[antiddos]${NC} $*"; }
warn() { echo -e "${YELLOW}[antiddos]${NC} $*" >&2; }
fail() { echo -e "${RED}[antiddos]${NC} $*" >&2; exit 1; }

trap 'fail "El gestor se detuvo en la línea $LINENO."' ERR

[[ $EUID -eq 0 ]] || fail "Ejecútalo como root: añade sudo delante del comando."

detect_panel() {
    if [[ -n "$PANEL_DIR" ]]; then echo "${PANEL_DIR%/}"; return; fi
    if [[ -f "$STATE_FILE" ]]; then
        local saved
        saved="$(grep -m1 -E '^PANEL_DIR=' "$STATE_FILE" 2>/dev/null | sed -E 's/^PANEL_DIR="?([^"]*)"?$/\1/')"
        if [[ -n "$saved" && -f "$saved/artisan" ]]; then echo "$saved"; return; fi
    fi
    local c
    for c in /var/www/pterodactyl /var/www/panel /var/www/html/pterodactyl /srv/pterodactyl; do
        [[ -f "$c/artisan" && -f "$c/config/app.php" ]] && { echo "$c"; return; }
    done
    echo ""
}

require_panel() {
    [[ -n "${PANEL:-}" ]] && return

    PANEL="$(detect_panel)"
    [[ -n "$PANEL" ]] || fail "No se encontró Pterodactyl en esta máquina. Si es un NODO usa 'node-install'; si es el panel: sudo antiddos $ACTION --panel=/ruta/al/panel"
    ok "Panel detectado en: $PANEL"
}

pkg_install() {
    local pkg="$1"
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg"
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q "$pkg"
    elif command -v yum >/dev/null 2>&1; then
        yum install -y -q "$pkg"
    else
        fail "No se pudo instalar '$pkg' automáticamente. Instálalo y reintenta."
    fi
}

require_cmd() {
    local cmd="$1" pkg="${2:-$1}"
    command -v "$cmd" >/dev/null 2>&1 && return
    info "Instalando $pkg…"
    pkg_install "$pkg"
    command -v "$cmd" >/dev/null 2>&1 || fail "'$cmd' sigue sin estar disponible."
    ok "$pkg instalado."
}

kit_dir() {
    if [[ -f "$SRC_DIR/$NODE_REL" ]]; then echo "$SRC_DIR"; else echo "$STATE_DIR/source"; fi
}

sync_sources() {
    require_cmd git
    if [[ -d "$SRC_DIR/.git" ]]; then
        info "Actualizando el código en $SRC_DIR…"
        git -C "$SRC_DIR" remote set-url origin "$REPO_URL"
        git -C "$SRC_DIR" fetch --quiet --depth 1 origin "+refs/heads/$REPO_BRANCH:refs/remotes/origin/$REPO_BRANCH"
        git -C "$SRC_DIR" reset --hard --quiet "origin/$REPO_BRANCH"
        git -C "$SRC_DIR" clean -fd --quiet
    else
        info "Descargando el repositorio en $SRC_DIR…"
        rm -rf "$SRC_DIR"
        git clone --quiet --branch "$REPO_BRANCH" --depth 1 "$REPO_URL" "$SRC_DIR"
    fi

    find "$SRC_DIR" -name '*.sh' -print0 | xargs -0 -r sed -i 's/\r$//'
    [[ -f "$SRC_DIR/bin/antiddos" ]] && sed -i 's/\r$//' "$SRC_DIR/bin/antiddos"
    chmod +x "$SRC_DIR"/*.sh 2>/dev/null || true
    chmod +x "$SRC_DIR/scripts"/*.sh 2>/dev/null || true
    chmod +x "$SRC_DIR/bin/antiddos" 2>/dev/null || true

    [[ -f "$SRC_DIR/$INSTALLER_REL" ]] || fail "Falta $INSTALLER_REL en el repositorio."

    ok "Código sincronizado (commit $(git -C "$SRC_DIR" rev-parse --short HEAD))."
}

remote_has_changes() {
    git -C "$SRC_DIR" fetch --quiet --depth 1 origin "+refs/heads/$REPO_BRANCH:refs/remotes/origin/$REPO_BRANCH"
    [[ "$(git -C "$SRC_DIR" rev-parse HEAD)" != "$(git -C "$SRC_DIR" rev-parse "origin/$REPO_BRANCH")" ]]
}

is_installed() {
    [[ -f "$STATE_FILE" ]] && return 0
    local panel; panel="$(detect_panel)"
    [[ -n "$panel" && -d "$panel/$TARGET_REL" ]]
}

node_installed() { [[ -x "$FW_BIN" ]]; }

install_cli() {
    if [[ -f "$SRC_DIR/bin/antiddos" ]]; then
        install -m 0755 "$SRC_DIR/bin/antiddos" "$CLI_PATH"
    else
        install -m 0755 "$SRC_DIR/antiddos.sh" "$CLI_PATH"
    fi
    ok "Comando corto disponible: antiddos"
}

run_installer() {
    local dir="$SRC_DIR"
    if [[ ! -f "$dir/$INSTALLER_REL" && "${1:-}" == "uninstall" && -f "$STATE_DIR/source/$INSTALLER_REL" ]]; then
        dir="$STATE_DIR/source"
    fi
    [[ -f "$dir/$INSTALLER_REL" ]] || { sync_sources; dir="$SRC_DIR"; }
    PANEL_DIR="$PANEL" WEB_USER="$WEB_USER" ANTIDDOS_REPO="$REPO_URL" SRC_DIR="$dir" \
        bash "$dir/$INSTALLER_REL" "$@"
}

installed_version() {
    local version=""

    if [[ -f "$STATE_FILE" ]]; then
        version="$(grep -m1 -E '^ADDON_INSTALLED_VERSION=' "$STATE_FILE" 2>/dev/null \
            | sed -E 's/^ADDON_INSTALLED_VERSION="?([^"]*)"?$/\1/')"
    fi

    if [[ -z "$version" ]]; then
        local panel; panel="$(detect_panel)"
        if [[ -n "$panel" && -f "$panel/$TARGET_REL/VERSION" ]]; then
            version="$(tr -d '[:space:]' < "$panel/$TARGET_REL/VERSION")"
        fi
    fi

    echo "${version:-desconocida}"
}

check_disk() {
    local dir="${PANEL_DIR:-${PANEL:-/var/www/pterodactyl}}" free need=800
    [ -d "$dir" ] || dir=/
    free="$(df -Pm "$dir" 2>/dev/null | awk 'NR==2 {print $4}')"
    case "$free" in ''|*[!0-9]*) return 0 ;; esac
    if [ "$free" -lt "$need" ]; then
        fail "El disco esta casi lleno (${free} MB libres, hacen falta ${need} MB). Libera espacio y vuelve a intentarlo. Para ver que ocupa: du -xh / --max-depth=2 2>/dev/null | sort -rh | head -20"
    fi
}

slim_sources() {
    local d
    for d in "$STATE_DIR/source" "$SRC_DIR"; do
        [ -n "$d" ] && [ -d "$d" ] || continue
        { [ -f "$d/extension.json" ] || [ -f "$d/VERSION" ]; } || continue
        { [ -f "$d/$INSTALLER_REL" ] || [ -f "$d/tools/register-provider.php" ] || [ -f "$d/scripts/patcher.php" ]; } || continue
        find "$d" -mindepth 1 -maxdepth 1 ! -name scripts ! -name tools ! -name patches ! -name bin ! -name '*.sh' \
            ! -name extension.json ! -name VERSION ! -name .gitattributes ! -name .vexa-slim -exec rm -rf {} + 2>/dev/null || true
        date '+%F %T' > "$d/.vexa-slim" 2>/dev/null || true
        chmod -R o-rwx "$d" 2>/dev/null || true
    done
    return 0
}

do_install() {
    check_disk
    require_panel

    if is_installed; then
        install_menu
        return
    fi

    sync_sources

    info "Instalando la extensión en el panel…"
    run_installer install
    install_cli
    slim_sources
}

install_menu() {
    local current; current="$(installed_version)"

    echo
    warn "Anti-DDoS y Optimización ya está instalado (versión ${current}) en ${PANEL}."
    echo
    echo "  1) Reinstalar  — vuelve a copiar todos los archivos y parches del panel."
    echo "                   NO se toca la base de datos: ajustes, bloqueos, lista"
    echo "                   blanca y la clave de la API se conservan."
    echo "  2) Actualizar  — descarga la última versión del repositorio y la instala."
    echo "  3) Cancelar    — no hacer nada."
    echo
    echo "  Para desinstalar: sudo antiddos uninstall"
    echo

    if [[ "$FORCE" == "1" || ! -t 0 ]]; then
        info "Modo no interactivo: se reinstala conservando la base de datos."
        do_reinstall
        return
    fi

    local answer
    read -r -p "Elige una opción [1-3] (1): " answer
    answer="${answer:-1}"

    case "$answer" in
        1|r|R|reinstalar) do_reinstall ;;
        2|u|U|actualizar) FORCE=1; do_update ;;
        3|c|C|cancelar|n|N) info "Cancelado. No se ha modificado nada." ;;
        *) fail "Opción no válida: $answer" ;;
    esac
}

do_reinstall() {
    require_panel

    if ! is_installed; then
        warn "No se detectó ninguna instalación previa; se hará una instalación limpia."
        do_install
        return
    fi

    info "Reinstalando la extensión (los datos de la base de datos se conservan)…"
    sync_sources
    run_installer install
    install_cli
    if node_installed; then
        info "Actualizando también el agente de este nodo…"
        bash "$(kit_dir)/$NODE_REL" install --yes
    fi
    ok "Extensión reinstalada. No se ha borrado ningún dato."
    slim_sources
}

do_update() {
    check_disk
    if [[ -z "$(detect_panel)" ]] && node_installed; then
        fetch_from_panel || sync_sources
        info "Actualizando el agente de nodo…"
        bash "$(kit_dir)/$NODE_REL" install --yes
        install_cli
        ok "Agente de nodo actualizado (configuración conservada)."
        return
    fi

    require_panel

    if ! is_installed; then
        warn "No se detectó ninguna instalación previa; se hará una instalación limpia."
        do_install
        return
    fi

    if [[ "$FORCE" != "1" && -d "$SRC_DIR/.git" ]] && ! remote_has_changes; then
        ok "Ya estás en la última versión. Nada que hacer."
        return
    fi

    info "Actualizando la extensión…"
    sync_sources
    run_installer install
    install_cli
    if node_installed; then
        bash "$(kit_dir)/$NODE_REL" install --yes
    fi
    ok "Extensión actualizada a la última versión."
    slim_sources
}

do_uninstall() {
    if [[ "${ANTIDDOS_DETACHED:-0}" != "1" ]]; then
        local tmp; tmp="$(mktemp /tmp/antiddos-XXXXXX.sh)"
        cat "${BASH_SOURCE[0]}" > "$tmp"
        chmod +x "$tmp"
        ANTIDDOS_DETACHED=1 FORCE="$FORCE" DROP_DATA="$DROP_DATA" \
            PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" exec bash "$tmp" uninstall
    fi

    local has_panel=0
    [[ -n "$(detect_panel)" ]] && is_installed && has_panel=1
    if [[ "$has_panel" == "0" ]] && ! node_installed; then
        fail "No se detectó ninguna instalación de Anti-DDoS y Optimización (ni panel ni agente de nodo)."
    fi

    warn "Se eliminará TODO lo de Anti-DDoS y Optimización en esta máquina: extensión del panel, agente de nodo, límites de nginx, cron, comando y el código en $SRC_DIR."
    if [[ "$DROP_DATA" == "1" ]]; then
        warn "Además se BORRARÁN las tablas de Anti-DDoS y Optimización (ajustes, bloqueos, eventos y estado de optimizaciones)."
    else
        info "Las tablas de la base de datos se conservan (añade --drop-data para borrarlas)."
    fi
    if [[ "$FORCE" != "1" && -t 0 ]]; then
        read -r -p "¿Continuar? [s/N] " answer
        [[ "$answer" =~ ^([sS]|[yY]|si|SI|sí|SÍ)$ ]] || { info "Cancelado."; return; }
    fi

    info "Desinstalando…"
    if [[ "$has_panel" == "1" ]]; then
        require_panel
        local args=(uninstall --force)
        [[ "$DROP_DATA" == "1" ]] && args+=(--drop-data)
        run_installer "${args[@]}" || warn "El desinstalador del panel terminó con errores; se continúa con la limpieza."
    fi

    if node_installed; then
        [[ -f "$(kit_dir)/$NODE_REL" ]] && bash "$(kit_dir)/$NODE_REL" uninstall || warn "No se pudo quitar el agente de nodo con el script; revisa 'systemctl list-units antiddos*'."
    fi

    [[ -f "$(kit_dir)/scripts/nginx-shield.sh" ]] && bash "$(kit_dir)/scripts/nginx-shield.sh" off >/dev/null 2>&1 || true

    autoupdate_off quiet
    rm -rf "$SRC_DIR"
    rm -f "$CLI_PATH"
    ok "Anti-DDoS y Optimización desinstalado por completo. No queda nada."
}

do_install_all() {
    require_panel
    sync_sources

    info "1/2 · Instalando la extensión en el panel…"
    run_installer install
    install_cli

    info "2/2 · Instalando el firewall anti-DDoS en este nodo…"
    local key url
    key="$(od -An -N24 -tx1 /dev/urandom | tr -d ' 
')"
    run_installer apikey --key="$key" >/dev/null
    url="$(grep -m1 -E '^APP_URL=' "$PANEL/.env" 2>/dev/null | sed -E 's/^APP_URL=//; s/^"//; s/"$//; s#/$##' || true)"

    local args=(install --panel-dir="$PANEL" --key="$key")
    [[ "$url" =~ ^https?://[A-Za-z0-9._:/-]+$ ]] && args+=(--panel-url="$url")
    [[ "$FORCE" == "1" ]] && args+=(--yes)
    bash "$(kit_dir)/$NODE_REL" "${args[@]}" "${PASSTHRU[@]}"

    ok "Todo instalado: panel + nodo. Los bloqueos del panel llegan al firewall en menos de 30 s."
    info "Panel: ${url:-tu-panel}/admin/extensions/antiddos   ·   Estado: sudo antiddos status"
}

fetch_from_panel() {
    local url="${NODE_PANEL_URL%/}" key="$API_KEY" conf="/etc/antiddos/agent.conf"
    if [[ ( -z "$url" || -z "$key" ) && -f "$conf" ]]; then
        [[ -z "$url" ]] && url="$(grep -m1 -E '^PANEL_URL=' "$conf" 2>/dev/null | sed -E 's/^PANEL_URL="?([^"]*)"?$/\1/')"
        [[ -z "$key" ]] && key="$(grep -m1 -E '^API_KEY=' "$conf" 2>/dev/null | sed -E 's/^API_KEY="?([^"]*)"?$/\1/')"
    fi
    url="${url%/}"
    [[ -n "$url" && -n "$key" ]] || return 1
    [[ "$url" =~ ^https?://[A-Za-z0-9._:-]+(/[A-Za-z0-9._~%!\$\&\(\)*+,;=:@/-]*)?$ ]] || return 1
    require_cmd curl

    local tmp http
    tmp="$(mktemp -d /tmp/antiddos-bundle.XXXXXX)"
    info "Descargando el agente desde ${url} (sin pasar por GitHub)…"
    http="$(curl -sS --max-time 60 -o "$tmp/bundle.tar.gz" -w '%{http_code}' \
        -H "X-Antiddos-Key: $key" "$url/api/antiddos/agent-bundle" 2>/dev/null)" || http=0

    if [[ "$http" != "200" ]]; then
        warn "El panel no entregó el agente (HTTP $http): se prueba descargando de GitHub."
        rm -rf "$tmp"
        return 1
    fi

    rm -rf "$SRC_DIR"
    mkdir -p "$SRC_DIR"
    if ! tar -xzf "$tmp/bundle.tar.gz" -C "$SRC_DIR" 2>/dev/null || [[ ! -f "$SRC_DIR/$NODE_REL" ]]; then
        warn "El paquete descargado del panel no es válido: se prueba descargando de GitHub."
        rm -rf "$tmp" "$SRC_DIR"
        return 1
    fi

    find "$SRC_DIR" -name '*.sh' -print0 | xargs -0 -r sed -i 's/\r$//'
    chmod +x "$SRC_DIR"/*.sh "$SRC_DIR/scripts"/*.sh 2>/dev/null || true
    rm -rf "$tmp"
    ok "Agente descargado del panel."
}

do_node_install() {
    fetch_from_panel || sync_sources
    [[ -f "$(kit_dir)/$NODE_REL" ]] || fail "Falta $NODE_REL en el repositorio."
    bash "$(kit_dir)/$NODE_REL" install "${PASSTHRU[@]}" $([[ "$FORCE" == "1" ]] && echo --yes)
    install_cli
}

do_node_uninstall() {
    [[ -f "$(kit_dir)/$NODE_REL" ]] || fail "No se encuentra el agente en $SRC_DIR. ¿Está instalado?"
    bash "$(kit_dir)/$NODE_REL" uninstall
}

do_node_status() {
    if [[ -f "$(kit_dir)/$NODE_REL" ]]; then
        bash "$(kit_dir)/$NODE_REL" status
    elif node_installed; then
        "$FW_BIN" status
    else
        echo "Agente de nodo: no instalado (sudo antiddos node-install)"
    fi
}

need_fw() { node_installed || fail "El firewall no está instalado en esta máquina. Ejecuta primero: sudo antiddos node-install"; }

do_fw() { need_fw; exec "$FW_BIN" "$@"; }

do_ban() {
    local ip="${1:-}" minutes="${2:-}"
    [[ -n "$ip" ]] || fail "Uso: antiddos ban <IP> [minutos]   (0 = permanente)"
    local did=0
    if node_installed; then
        local secs=""
        [[ -n "$minutes" ]] && secs=$(( minutes * 60 ))
        "$FW_BIN" ban "$ip" ${secs:+$secs} && did=1
    fi
    if [[ -n "$(detect_panel)" ]] && is_installed; then
        require_panel
        run_installer artisan ban "$ip" ${minutes:+--minutes="$minutes"} && did=1
    fi
    [[ "$did" == "1" ]] || fail "No hay ni panel ni agente de nodo instalados en esta máquina."
}

do_unban() {
    local ip="${1:-}"
    [[ -n "$ip" ]] || fail "Uso: antiddos unban <IP>"
    local did=0
    node_installed && "$FW_BIN" unban "$ip" && did=1
    if [[ -n "$(detect_panel)" ]] && is_installed; then
        require_panel
        run_installer artisan unban "$ip" && did=1
    fi
    [[ "$did" == "1" ]] || fail "No hay ni panel ni agente de nodo instalados en esta máquina."
}

do_bans() {
    node_installed && "$FW_BIN" bans
    if [[ -n "$(detect_panel)" ]] && is_installed; then
        require_panel
        run_installer artisan bans
    fi
}

do_whitelist() {
    local sub="${1:-list}" ip="${2:-}"
    case "$sub" in
        add)
            [[ -n "$ip" ]] || fail "Uso: antiddos whitelist add <IP|CIDR>"
            node_installed && "$FW_BIN" white-add "$ip"
            if [[ -n "$(detect_panel)" ]] && is_installed; then require_panel; run_installer artisan whitelist add "$ip"; fi ;;
        del|remove)
            [[ -n "$ip" ]] || fail "Uso: antiddos whitelist del <IP|CIDR>"
            node_installed && "$FW_BIN" white-del "$ip"
            if [[ -n "$(detect_panel)" ]] && is_installed; then require_panel; run_installer artisan whitelist del "$ip"; fi ;;
        list|ls)
            node_installed && "$FW_BIN" white-list
            if [[ -n "$(detect_panel)" ]] && is_installed; then require_panel; run_installer artisan whitelist list; fi ;;
        *) fail "Uso: antiddos whitelist add|del|list [IP|CIDR]" ;;
    esac
}

do_net() {
    local kind="$1"; shift
    { [[ -n "$(detect_panel)" ]] && is_installed; } || fail "Los operadores y países se bloquean desde el panel: ejecuta este comando en la máquina del panel (o usa la pestaña Bloqueos)."
    require_panel
    local extra=() a
    for a in "${PASSTHRU[@]}"; do
        [[ "$a" == --hours=* || "$a" == --reason=* ]] && extra+=("$a")
    done
    [[ "$FORCE" == "1" ]] && extra+=(--force)
    run_installer artisan "$kind" "$@" "${extra[@]}" || exit 1
}

do_ip() {
    [[ -n "${1:-}" ]] || fail "Uso: antiddos ip <IP>"
    require_panel
    run_installer artisan ip "$1" || exit 1
}

do_nginx() {
    [[ -f "$(kit_dir)/scripts/nginx-shield.sh" ]] || sync_sources
    bash "$(kit_dir)/scripts/nginx-shield.sh" "$1" "${PASSTHRU[@]}"
}

autoupdate_on() {
    [[ -f "$SRC_DIR/antiddos.sh" ]] || sync_sources
    local line="0 4 * * * /usr/bin/env bash $SRC_DIR/antiddos.sh update >> $LOG_FILE 2>&1 # $CRON_MARKER"
    ( crontab -l 2>/dev/null | grep -v "$CRON_MARKER" || true; echo "$line" ) | crontab -
    ok "Actualización automática activada (cada día a las 04:00)."
    info "Registro: $LOG_FILE"
}

autoupdate_off() {
    if crontab -l 2>/dev/null | grep -q "$CRON_MARKER"; then
        crontab -l 2>/dev/null | grep -v "$CRON_MARKER" | crontab -
        [[ "${1:-}" == "quiet" ]] || ok "Actualización automática desactivada."
    else
        [[ "${1:-}" == "quiet" ]] || info "La actualización automática no estaba activada."
    fi
}

do_status() {
    PANEL="$(detect_panel)"
    if [[ -f "$SRC_DIR/$INSTALLER_REL" && -n "$PANEL" ]]; then
        run_installer status
    else
        echo "Anti-DDoS y Optimización"
        echo "  Panel (extensión) : $(is_installed && echo presente || echo ausente)"
        echo "  Panel             : ${PANEL:-no detectado en esta máquina}"
        echo "  Código            : $([[ -d "$SRC_DIR" ]] && echo "$SRC_DIR" || echo 'no descargado')"
        echo "  CLI               : $([[ -x "$CLI_PATH" ]] && echo "$CLI_PATH" || echo 'no instalado')"
    fi
    echo
    if node_installed; then do_node_status; else echo "  Agente de nodo    : no instalado en esta máquina"; fi
    echo "  Auto-update       : $(crontab -l 2>/dev/null | grep -q "$CRON_MARKER" && echo activo || echo inactivo)"
}

do_apikey() {
    require_panel
    if [[ -n "$API_KEY" ]]; then
        run_installer apikey --key="$API_KEY"
    else
        run_installer apikey
    fi
}

do_info()  { require_panel; run_installer info; }
do_prune() { require_panel; run_installer prune; }

do_version() {
    if [[ -f "$SRC_DIR/VERSION" ]]; then
        tr -d '[:space:]' < "$SRC_DIR/VERSION"; echo
    else
        echo "desconocida (el código fuente no está descargado)"
    fi
}

usage() {
    cat <<EOF
Anti-DDoS y Optimización — anti-DDoS y optimización para Pterodactyl

TODO EN UNO (panel y Wings en la misma máquina)
  install-all [--tcp=25565 --udp=19132]   Instala panel + firewall del nodo; la clave se gestiona sola

PANEL (extensión + escudo web)
  install               Instala la extensión en el panel
                        (si ya está instalada, ofrece reinstalar o actualizar)
  reinstall             Reinstala todos los archivos SIN tocar la base de datos
  update                Actualiza a la última versión (panel y/o agente de nodo)
  uninstall             Desinstala TODO (extensión, agente, cron y comando)
  status                Estado de la instalación
  version               Versión descargada
  apikey [--key=X]      Genera (o fija) la clave de la API que usan los nodos
  info                  Configuración y estadísticas actuales
  prune                 Limpia bloqueos caducados y eventos antiguos
  nginx-on [--rate=20 --burst=100 --conn=50]   Límites de peticiones en nginx (capa 7)
  nginx-off | nginx-status

NODO (firewall anti-DDoS contra ataques a los servidores de Minecraft)
  node-install [--tcp=25565 --udp=19132 --web=80,443 --ssh=22 --panel-url=URL --key=CLAVE]
                        Instala el firewall (iptables+ipset+sysctl) y el servicio systemd
  node-uninstall        Quita el agente (conserva /etc/antiddos)
  node-status           Estado del firewall
  fw <acción>           apply | remove | status | top | sync | set CLAVE VALOR  (ver 'antiddos fw help')
  attack-on             Modo "bajo ataque": límites más estrictos solo para IPs desconocidas (los jugadores conectados no se ven afectados)
  attack-off            Vuelve a los límites normales

BLOQUEOS (firewall local y/o panel, según lo que haya en esta máquina)
  ban <IP> [minutos]    Bloquea una IP (0 = permanente)
  unban <IP>            Desbloquea
  bans                  Lista los bloqueos activos
  whitelist add|del|list [IP|CIDR]
  ip <IP>               Origen, operador, reputación y si se puede bloquear

OPERADORES Y PAÍSES (bloquea todas sus IPs, también las nuevas; en el panel y en todos los nodos)
  asn block AS199585 [--hours=24] [--reason="motivo"]   Bloquea un operador entero (vacío = permanente)
  asn unblock AS199585 | asn list | asn refresh
  country block PL [--hours=24]                          Bloquea un país entero (redes > 4 M de IPs: --force)
  country unblock PL | country list

OTROS
  autoupdate-on         Programa la actualización automática diaria (04:00)
  autoupdate-off        Cancela la actualización automática

Opciones:
  --force, -y           No pedir confirmación y forzar la actualización
  --drop-data           En uninstall, borra también las tablas de la extensión
  --panel=RUTA          Ruta del panel (por defecto se detecta automáticamente)
  --user=USUARIO        Usuario del servidor web (por defecto se detecta)
  --key=CLAVE           Clave concreta para 'apikey' / 'node-install'

Variables de entorno:
  PANEL_DIR, WEB_USER, REPO_URL, REPO_BRANCH, SRC_DIR (por defecto /opt/antiddos)

Ejemplos:
  bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) install-all
  sudo antiddos node-install --tcp=25565:25600 --udp=19132 --panel-url=https://panel.midominio.com --key=XXXX
  sudo antiddos attack-on
EOF
}

ACTION="${1:-help}"
shift || true

POS=()
for arg in "$@"; do
    case "$arg" in
        --force|-y|--yes) FORCE=1 ;;
        --drop-data)      DROP_DATA=1 ;;
        --key=*)          API_KEY="${arg#*=}"; PASSTHRU+=("$arg") ;;
        --panel-url=*)    NODE_PANEL_URL="${arg#*=}"; PASSTHRU+=("$arg") ;;
        --panel=*)        PANEL_DIR="${arg#*=}" ;;
        --user=*)         WEB_USER="${arg#*=}" ;;
        --*)              PASSTHRU+=("$arg") ;;
        *)                POS+=("$arg"); PASSTHRU+=("$arg") ;;
    esac
done

case "$ACTION" in
    ban)
        MIN=""
        for a in "${PASSTHRU[@]}"; do [[ "$a" == --minutes=* ]] && MIN="${a#*=}"; done
        [[ -z "$MIN" && ${#POS[@]} -ge 2 ]] && MIN="${POS[1]}"
        ;;
esac

case "$ACTION" in
    install)                do_install ;;
    reinstall|repair)       do_reinstall ;;
    update|upgrade)         do_update ;;
    uninstall|remove)       do_uninstall ;;
    status)                 do_status ;;
    version|--version)      do_version ;;
    apikey)                 do_apikey ;;
    info)                   do_info ;;
    prune)                  do_prune ;;
    install-all|all)        do_install_all ;;
    node-install)           do_node_install ;;
    node-uninstall)         do_node_uninstall ;;
    node-status)            do_node_status ;;
    fw)                     do_fw "${POS[@]}" ;;
    attack-on)              do_fw attack on ;;
    attack-off)             do_fw attack off ;;
    ban)                    do_ban "${POS[0]:-}" "${MIN:-}" ;;
    unban)                  do_unban "${POS[0]:-}" ;;
    bans)                   do_bans ;;
    whitelist|white)        do_whitelist "${POS[0]:-list}" "${POS[1]:-}" ;;
    asn|operador)           do_net asn "${POS[@]}" ;;
    country|pais|país)      do_net country "${POS[@]}" ;;
    ip)                     do_ip "${POS[0]:-}" ;;
    nginx-on)               do_nginx on ;;
    nginx-off)              do_nginx off ;;
    nginx-status)           do_nginx status ;;
    autoupdate-on)          autoupdate_on ;;
    autoupdate-off)         autoupdate_off ;;
    ''|help|-h|--help)      usage ;;
    *) echo "Acción desconocida: $ACTION" >&2; echo; usage; exit 1 ;;
esac
