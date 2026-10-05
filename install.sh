#!/usr/bin/env bash
#
# Anti-DDoS y Optimización — instalador público
# Descarga el gestor (antiddos.sh) desde el repositorio y lo ejecuta.
#
#   Panel:  bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) install
#   Nodo:   bash <(curl -sSL https://raw.githubusercontent.com/richi080oficial-cloud/Anti-DDOS-y-Optimizacion/main/install.sh) node-install
#
# Autor: Vexa Studio · Licencia: MIT
#
set -Eeuo pipefail

# ── Configuración ─────────────────────────────────────────────────────────────
# Repositorio público: no se necesita ninguna credencial.
_OWNER="${REPO_OWNER:-richi080oficial-cloud}"
_REPO="${REPO_NAME:-Anti-DDOS-y-Optimizacion}"
_BRANCH="${REPO_BRANCH:-main}"
_FILE="antiddos.sh"

RED='\033[0;31m'; BLUE='\033[0;36m'; NC='\033[0m'
info() { echo -e "${BLUE}[antiddos]${NC} $*"; }
fail() { echo -e "${RED}[antiddos]${NC} $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Ejecútalo como root: añade sudo delante del comando."

command -v curl >/dev/null 2>&1 || fail "curl no está instalado. Instálalo y reintenta."

# ── Descargar el gestor ───────────────────────────────────────────────────────
_URL="https://raw.githubusercontent.com/$_OWNER/$_REPO/$_BRANCH/$_FILE"

_TMP="$(mktemp /tmp/antiddos-XXXXXX.sh)"
trap 'rm -f "$_TMP"' EXIT

info "Descargando el gestor desde $_OWNER/$_REPO ($_BRANCH)…"

http_code="$(curl -sSL -o "$_TMP" -w '%{http_code}' \
    -H 'Cache-Control: no-cache' \
    "$_URL")" || fail "No se pudo conectar con GitHub. Revisa la red o el DNS del servidor."

case "$http_code" in
    200) ;;
    404) fail "No se encontró $_FILE en $_OWNER/$_REPO@$_BRANCH. Revisa que el repositorio sea público." ;;
    403) fail "GitHub rechazó la petición (403). Puede ser un límite temporal: espera un momento y reintenta." ;;
    *)   fail "Error HTTP $http_code al descargar $_FILE." ;;
esac

[[ -s "$_TMP" ]] || fail "El archivo descargado está vacío. Reintenta en unos segundos."
head -n 1 "$_TMP" | grep -q '^#!' || fail "El archivo descargado no es un script válido."

# Normalizar saltos de línea por si el repo se editó desde Windows.
sed -i 's/\r$//' "$_TMP"
chmod +x "$_TMP"

# El gestor usa estas variables para clonar el repositorio.
export REPO_URL="https://github.com/$_OWNER/$_REPO.git"
export REPO_BRANCH="$_BRANCH"

exec bash "$_TMP" "${@:-install}"
