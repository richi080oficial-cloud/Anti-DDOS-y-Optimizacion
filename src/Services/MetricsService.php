<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;

/**
 * Convierte las muestras de contadores acumulados de los agentes en series de
 * VELOCIDAD (paquetes por minuto) listas para dibujar.
 *
 * Reglas para no mostrar nada que no sea cierto:
 *   - Cada punto es la velocidad media REAL del tramo: incremento de contador
 *     dividido por los segundos que realmente pasaron entre las dos muestras.
 *     Asi un tramo a medias (el ultimo) no parece una caida.
 *   - Si entre dos muestras pasan mas de GAP segundos (agente apagado, panel
 *     caido), ese tramo se descarta: se queda como hueco (null), nunca como
 *     un pico que reparte horas de trafico en un solo punto.
 *   - Un contador que baja se interpreta como reinicio (las reglas se
 *     reaplicaron): el incremento es el valor nuevo.
 *   - Con varios nodos, las velocidades de cada nodo se SUMAN.
 *   - «Ahora» (IPs bloqueadas) sale de la ultima muestra reciente, no de la barra.
 */
class MetricsService
{
    /** rango => [ventana en segundos, tamano de cada punto en segundos] */
    public const RANGES = [
        '1h' => [3600, 30],
        '6h' => [21600, 300],
        '24h' => [86400, 900],
        '7d' => [604800, 3600],
    ];

    /** Segundos entre muestras a partir de los cuales el tramo se considera un hueco. */
    public const GAP = 150;

    /** Segundos sin muestras tras los cuales los datos se consideran viejos. */
    public const STALE = 120;

    protected const KEYS = ['dropped', 'syn', 'tcp_pkts', 'udp_pkts'];

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
        $count = intdiv($end - $start, $bucket) + 1;

        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = $start + $i * $bucket;
        }

        // Una muestra anterior a la ventana sirve de base para el primer incremento.
        $rows = ShieldMetric::query()
            ->where('recorded_at', '>=', Carbon::createFromTimestamp($from - self::GAP))
            ->when($host !== null && $host !== '', fn ($q) => $q->where('host', $host))
            ->orderBy('host')
            ->orderBy('recorded_at')
            ->get();

        // acc[host][idx] = ['dt' => segundos, 'dropped' => incremento, ...]
        $acc = [];
        $totals = array_fill_keys(self::KEYS, 0);
        $prev = [];
        $latest = [];
        $last = 0;
        $intervals = 0;

        foreach ($rows as $row) {
            $ts = $row->recorded_at->getTimestamp();
            $h = $row->host;
            $p = $prev[$h] ?? null;
            $prev[$h] = $row;
            $latest[$h] = $row;
            $last = max($last, $ts);

            if ($p === null || $ts < $start) {
                continue;
            }

            $dt = $ts - $p->recorded_at->getTimestamp();

            if ($dt <= 0 || $dt > self::GAP) {
                continue;
            }

            $idx = intdiv($ts - $start, $bucket);

            if ($idx < 0 || $idx >= $count) {
                continue;
            }

            $acc[$h][$idx]['dt'] = ($acc[$h][$idx]['dt'] ?? 0) + $dt;
            $intervals++;

            foreach (self::KEYS as $k) {
                $delta = $this->delta((int) $p->{$k}, (int) $row->{$k});
                $acc[$h][$idx][$k] = ($acc[$h][$idx][$k] ?? 0) + $delta;
                $totals[$k] += $delta;
            }
        }

        $series = ['dropped' => [], 'syn' => [], 'tcp' => [], 'udp' => []];
        $map = ['dropped' => 'dropped', 'syn' => 'syn', 'tcp' => 'tcp_pkts', 'udp' => 'udp_pkts'];
        $peak = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $hasData = false;
            $rate = array_fill_keys(array_keys($series), 0.0);

            foreach ($acc as $perHost) {
                $cell = $perHost[$i] ?? null;

                if ($cell === null || ($cell['dt'] ?? 0) <= 0) {
                    continue;
                }

                $hasData = true;

                foreach ($map as $name => $k) {
                    $rate[$name] += ($cell[$k] ?? 0) / $cell['dt'] * 60;
                }
            }

            foreach ($series as $name => $_) {
                $series[$name][] = $hasData ? round($rate[$name], 1) : null;
            }

            if ($hasData) {
                $peak = max($peak, $rate['dropped']);
            }
        }

        // IPs bloqueadas «ahora»: ultima muestra de cada nodo, solo si es reciente.
        $bansNow = 0;

        foreach ($latest as $row) {
            if ($now - $row->recorded_at->getTimestamp() <= self::STALE) {
                $bansNow += (int) $row->bans;
            }
        }

        return [
            'range' => $range,
            'bucket' => $bucket,
            'labels' => $labels,
            'series' => $series,
            'hosts' => ShieldMetric::query()->distinct()->orderBy('host')->pluck('host')->all(),
            'totals' => [
                'dropped' => $totals['dropped'],
                'syn' => $totals['syn'],
                'tcp' => $totals['tcp_pkts'],
                'udp' => $totals['udp_pkts'],
                'peak_dropped_per_min' => (int) round($peak),
                'bans_now' => $bansNow,
            ],
            'intervals' => $intervals,
            'has_data' => $intervals > 0,
            'last_sample' => $last ?: null,
            'stale' => $last === 0 || ($now - $last) > self::STALE,
            'generated_at' => $now,
        ];
    }

    protected function delta(int $before, int $after): int
    {
        return $after >= $before ? $after - $before : $after;
    }
}
