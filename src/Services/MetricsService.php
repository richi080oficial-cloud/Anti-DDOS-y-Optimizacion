<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $range = isset(self::RANGES[$range]) ? $range : '1h';

        // El historial cambia como mucho cada ~25 s (una muestra por agente): no se
        // recalcula en cada refresco de la pantalla (cada 15 s y por cada admin).
        return Cache::remember(
            'antiddos:metrics:' . md5($range . '|' . $host . '|' . $view),
            $range === '1h' ? 10 : 60,
            fn () => $this->build($range, $host, $view)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(string $range, ?string $host, string $view): array
    {
        [$window, $bucket] = self::RANGES[$range];

        $now = microtime(true);
        $from = (int) floor($now) - $window;
        $start = intdiv($from, $bucket) * $bucket;
        $count = intdiv((int) floor($now) - $start, $bucket) + 1;

        // Se pide una muestra anterior a la ventana para poder calcular el primer incremento.
        // toBase() + solo las columnas necesarias: filas simples en vez de modelos de
        // Eloquent (7 dias son ~24.000 filas por nodo; como modelos ocupaban >100 MB).
        $rows = ShieldMetric::query()->toBase()
            ->select(array_merge(['host', 'recorded_at', 'bans'], array_keys(self::COLUMNS)))
            ->where('recorded_at', '>=', Carbon::createFromTimestamp((int) ($from - self::GAP)))
            ->when($host !== null && $host !== '', fn ($q) => $q->where('host', $host))
            ->orderBy('host')
            ->orderBy('recorded_at')
            ->cursor();

        $sources = [];

        foreach ($rows as $row) {
            $sample = ['t' => (float) strtotime((string) $row->recorded_at), 'bans' => (int) $row->bans];

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
