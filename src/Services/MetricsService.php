<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;

/**
 * Historial de trafico: lee las muestras guardadas en la base de datos (una cada
 * ~25 s por agente) y calcula las series con TrafficEngine, igual que el modo en
 * vivo.
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
    public const GAP = 150.0;

    /** Segundos sin muestras tras los cuales los datos se consideran viejos. */
    public const STALE = 120.0;

    /** columna de la tabla => contador del motor */
    protected const COLUMNS = [
        'tcp_pkts' => 'tcp', 'udp_pkts' => 'udp', 'syn' => 'syn', 'tcp_bytes' => 'tcp_b', 'udp_bytes' => 'udp_b',
        'd_ban' => 'd_ban', 'd_mal' => 'd_mal', 'd_icmp' => 'd_icmp', 'd_syn' => 'd_syn', 'd_udp' => 'd_udp',
        'd_conn' => 'd_conn', 'd_web' => 'd_web', 'drop_bytes' => 'd_bytes',
        'rx_bytes' => 'rx_b', 'rx_pkts' => 'rx_p', 'tx_bytes' => 'tx_b', 'tx_pkts' => 'tx_p',
    ];

    public function __construct(protected TrafficEngine $engine)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function series(string $range = '1h', ?string $host = null, string $view = 'packets'): array
    {
        [$window, $bucket] = self::RANGES[$range] ?? self::RANGES['1h'];

        $now = microtime(true);
        $from = (int) floor($now) - $window;
        $start = intdiv($from, $bucket) * $bucket;
        $count = intdiv((int) floor($now) - $start, $bucket) + 1;

        // Se pide una muestra anterior a la ventana para poder calcular el primer incremento.
        $rows = ShieldMetric::query()
            ->where('recorded_at', '>=', Carbon::createFromTimestamp((int) ($from - self::GAP)))
            ->when($host !== null && $host !== '', fn ($q) => $q->where('host', $host))
            ->orderBy('host')
            ->orderBy('recorded_at')
            ->get();

        $sources = [];

        foreach ($rows as $row) {
            $sample = ['t' => (float) $row->recorded_at->getTimestamp(), 'bans' => (int) $row->bans];

            foreach (self::COLUMNS as $column => $counter) {
                $sample[$counter] = (int) $row->{$column};
            }

            $sources[$row->host][] = $sample;
        }

        $r = $this->engine->build($sources, $start, $count, $bucket, self::GAP, $now, $view, self::STALE);

        return [
            'range' => $range,
            'view' => $view,
            'bucket' => $bucket,
            'labels' => $r['labels'],
            'series' => $r['series'],
            'stats' => $r['stats'],
            'hosts' => ShieldMetric::query()->distinct()->orderBy('host')->pluck('host')->all(),
            'totals' => $r['totals'] + ['bans_now' => $r['bans_now']],
            'intervals' => $r['intervals'],
            'has_data' => $r['intervals'] > 0,
            'last_sample' => $r['last'],
            'stale' => $r['last'] === null || ($now - $r['last']) > self::STALE,
            'generated_at' => round($now, 3),
        ];
    }
}
