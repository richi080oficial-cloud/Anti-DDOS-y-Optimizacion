#!/usr/bin/env bash
#
# Anti-DDoS y Optimización — optimización del sistema del nodo para servidores de Minecraft
# Autor: Vexa Studio · Licencia: MIT
#
#   optimize-host.sh status    Muestra los valores actuales frente a los recomendados
#   optimize-host.sh apply     Aplica los ajustes y los hace persistentes
#   optimize-host.sh runtime   Solo lo que hay que repetir en cada arranque (lo usa systemd)
#   optimize-host.sh revert    Quita los archivos de Anti-DDoS y Optimización (los valores del kernel
#                              vuelven a los de fábrica en el próximo reinicio)
#
# Qué hace y por qué:
#   - vm.swappiness bajo:      el heap de Java no debe irse a swap (provoca tirones).
#   - THP en "madvise":        con -XX:+AlwaysPreTouch evita pausas largas del GC.
#   - Governor "performance":  Minecraft es muy sensible a la frecuencia de un solo hilo.
#   - BBR + fq:                mejor latencia/throughput con muchos jugadores.
#   - Buffers y límites de ficheros altos para muchos jugadores y muchos contenedores.
#
set -uo pipefail

SYSCTL_FILE="/etc/sysctl.d/97-antiddos-tune.conf"
LIMITS_FILE="/etc/security/limits.d/97-antiddos.conf"
WINGS_DROPIN="/etc/systemd/system/wings.service.d/antiddos.conf"

if [ -t 1 ]; then
    GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;36m'; RED=$'\033[0;31m'; NC=$'\033[0m'
else
    GREEN=''; YELLOW=''; BLUE=''; RED=''; NC=''
fi
info() { echo -e "${BLUE}[antiddos]${NC} $*"; }
ok()   { echo -e "${GREEN}[antiddos]${NC} $*"; }
warn() { echo -e "${YELLOW}[antiddos]${NC} $*" >&2; }
fail() { echo -e "${RED}[antiddos]${NC} $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Ejecútalo como root."

bbr_available() { grep -qw bbr /proc/sys/net/ipv4/tcp_available_congestion_control 2>/dev/null || modprobe tcp_bbr 2>/dev/null && grep -qw bbr /proc/sys/net/ipv4/tcp_available_congestion_control 2>/dev/null; }

write_sysctl() {
    {
        cat <<'EOF'
# Generado por Anti-DDoS y Optimización — no editar a mano (se regenera con: antiddos optimize-host)
vm.swappiness = 10
vm.vfs_cache_pressure = 50
vm.dirty_background_ratio = 5
vm.dirty_ratio = 15
fs.file-max = 2097152
fs.inotify.max_user_watches = 524288
fs.inotify.max_user_instances = 1024
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.tcp_rmem = 4096 87380 16777216
net.ipv4.tcp_wmem = 4096 65536 16777216
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_slow_start_after_idle = 0
net.ipv4.tcp_mtu_probing = 1
net.ipv4.ip_local_port_range = 10240 65535
net.core.somaxconn = 4096
net.core.netdev_max_backlog = 16384
EOF
        if bbr_available; then
            echo "net.core.default_qdisc = fq"
            echo "net.ipv4.tcp_congestion_control = bbr"
        fi
    } > "$SYSCTL_FILE"
    sysctl -e -q -p "$SYSCTL_FILE" >/dev/null 2>&1 || warn "Algunos valores de sysctl no se pudieron aplicar."
}

write_limits() {
    cat > "$LIMITS_FILE" <<'EOF'
# Generado por Anti-DDoS y Optimización
*    soft nofile 1048576
*    hard nofile 1048576
root soft nofile 1048576
root hard nofile 1048576
EOF
    if systemctl list-unit-files wings.service >/dev/null 2>&1 && systemctl cat wings.service >/dev/null 2>&1; then
        mkdir -p "$(dirname "$WINGS_DROPIN")"
        cat > "$WINGS_DROPIN" <<'EOF'
# Generado por Anti-DDoS y Optimización
[Service]
LimitNOFILE=1048576
TasksMax=infinity
EOF
        systemctl daemon-reload
        warn "Wings tendrá los nuevos límites de ficheros tras reiniciarlo: systemctl restart wings (los servidores se reinician)."
    fi
}

runtime_tune() {
    # Transparent Huge Pages: "madvise" evita los picos de latencia con Java.
    local thp=/sys/kernel/mm/transparent_hugepage
    if [[ -w "$thp/enabled" ]]; then
        echo madvise > "$thp/enabled" 2>/dev/null || true
        [[ -w "$thp/defrag" ]] && echo defer+madvise > "$thp/defrag" 2>/dev/null || true
    fi
    # Governor de CPU a "performance" si el sistema lo expone (en VPS suele no existir).
    local g
    for g in /sys/devices/system/cpu/cpu*/cpufreq/scaling_governor; do
        [[ -w "$g" ]] && echo performance > "$g" 2>/dev/null || true
    done
}

show_status() {
    printf '%-42s %-14s %s\n' "Ajuste" "Actual" "Recomendado"
    row() { printf '%-42s %-14s %s\n' "$1" "$2" "$3"; }
    row "vm.swappiness" "$(cat /proc/sys/vm/swappiness 2>/dev/null)" "10"
    row "net.ipv4.tcp_congestion_control" "$(cat /proc/sys/net/ipv4/tcp_congestion_control 2>/dev/null)" "bbr"
    row "net.core.default_qdisc" "$(cat /proc/sys/net/core/default_qdisc 2>/dev/null)" "fq"
    row "net.ipv4.tcp_syncookies" "$(cat /proc/sys/net/ipv4/tcp_syncookies 2>/dev/null)" "1"
    row "fs.file-max" "$(cat /proc/sys/fs/file-max 2>/dev/null)" "2097152"
    row "THP (enabled)" "$(grep -o '\[[a-z]*\]' /sys/kernel/mm/transparent_hugepage/enabled 2>/dev/null | tr -d '[]')" "madvise"
    row "CPU governor" "$(cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor 2>/dev/null || echo n/d)" "performance"
    row "Swap total (MiB)" "$(awk '/SwapTotal/ {print int($2/1024)}' /proc/meminfo)" "—"
    row "Núcleos / RAM (MiB)" "$(nproc) / $(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)" "—"
    echo
    echo "Persistencia: $([[ -f $SYSCTL_FILE ]] && echo 'aplicada por Anti-DDoS y Optimización' || echo 'no aplicada')"
}

case "${1:-status}" in
    status)  show_status ;;
    apply)
        write_sysctl
        write_limits
        runtime_tune
        ok "Sistema optimizado. Persistente tras reinicios (sysctl.d + antiddos-tune.service)."
        show_status
        ;;
    runtime) runtime_tune ;;
    revert)
        rm -f "$SYSCTL_FILE" "$LIMITS_FILE" "$WINGS_DROPIN"
        rmdir "$(dirname "$WINGS_DROPIN")" 2>/dev/null || true
        systemctl daemon-reload 2>/dev/null || true
        ok "Archivos de ajuste eliminados. Los valores de fábrica vuelven en el próximo reinicio."
        ;;
    *) echo "Uso: optimize-host.sh status|apply|runtime|revert"; exit 1 ;;
esac
