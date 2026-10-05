<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;

/**
 * Convierte las muestras de contadores acumulados de los agentes en series
 * temporales (paquetes por intervalo) listas para dibujar.
 *
 * Los contadores del firewall son acumulados y se reinician al reaplicar las
 * reglas: si una muestra es menor que la anterior se toma como reinicio y el
 * incremento es el propio valor.
 */
class MetricsService
{
    /** rango => [ventana en segundos, tamano de cada barra en segundos] */
    public const RANGES = [
        '1h' => [3600, 60],
        '6h' => [21600, 300],
        '24h' => [86400, 900],
        '7d' => [604800, 3600],
    ];

    /**
     * @return array<string, mixed>
     */
    public function series(string $range = '1h', ?string $host = null): array
    {
        [$window, $bucket] = self::RANGES[$range] ?? self::RANGES['1h'];

        $now = time();
        $from = $now - $window;
        $start = intdiv($from, $bucket) * $bucket;
        $end = intdiv($now, $bucket) * $bucket;

        $labels = [];
        for ($t = $start; $t <= $end; $t += $bucket) {
            $labels[] = $t;
        }

        $zero = array_fill(0, count($labels), 0);
        $series = ['dropped' => $zero, 'syn' => $zero, 'tcp' => $zero, 'udp' => $zero, 'bans' => $zero];

        // Se pide una muestra anterior a la ventana para poder calcular el primer incremento.
        $rows = ShieldMetric::query()
            ->where('recorded_at', '>=', Carbon::createFromTimestamp($from - $bucket - 120))
            ->when($host !== null && $host !== '', fn ($q) => $q->where('host', $host))
            ->orderBy('host')
            ->orderBy('recorded_at')
            ->get();

        $hosts = $rows->pluck('host')->unique()->values()->all();
        $banPeaks = [];
        $prev = [];

        foreach ($rows as $row) {
            $ts = $row->recorded_at->getTimestamp();
            $h = $row->host;
            $p = $prev[$h] ?? null;
            $prev[$h] = $row;

            if ($p === null || $ts < $start) {
                continue;
            }

            $idx = intdiv($ts - $start, $bucket);

            if (!isset($zero[$idx])) {
                continue;
            }

            $series['dropped'][$idx] += $this->delta($p->dropped, $row->dropped);
            $series['syn'][$idx] += $this->delta($p->syn, $row->syn);
            $series['tcp'][$idx] += $this->delta($p->tcp_pkts, $row->tcp_pkts);
            $series['udp'][$idx] += $this->delta($p->udp_pkts, $row->udp_pkts);

            $banPeaks[$h][$idx] = max($banPeaks[$h][$idx] ?? 0, (int) $row->bans);
        }

        foreach ($banPeaks as $perHost) {
            foreach ($perHost as $idx => $value) {
                $series['bans'][$idx] += $value;
            }
        }

        $minutes = max(1, $bucket / 60);

        return [
            'range' => $range,
            'bucket' => $bucket,
            'labels' => $labels,
            'series' => $series,
            'hosts' => $hosts,
            'totals' => [
                'dropped' => array_sum($series['dropped']),
                'syn' => array_sum($series['syn']),
                'tcp' => array_sum($series['tcp']),
                'udp' => array_sum($series['udp']),
                'peak_dropped_per_min' => (int) round(max($series['dropped'] ?: [0]) / $minutes),
                'bans_now' => (int) ($series['bans'][count($series['bans']) - 1] ?? 0),
            ],
            'has_data' => $rows->isNotEmpty(),
            'generated_at' => $now,
        ];
    }

    protected function delta(int $before, int $after): int
    {
        return $after >= $before ? $after - $before : $after;
    }
}
