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

    /**
     * Hueco en la vista de 7 dias: ahi entran muestras ya aclaradas (una cada 5 min),
     * asi que la separacion normal es de ~300 s y no debe pintarse como hueco.
     */
    public const GAP_THINNED = 420.0;

    /** Horas que se guardan con todas las muestras (~1 cada 30 s): cubren la vista de 24 h. */
    public const FULL_HOURS = 26;

    /** De ahi para atras se conserva una muestra cada este numero de segundos. */
    public const THIN_EVERY = 300;

    /** Dias que se guardan (la vista mas larga es de 7). */
    public const KEEP_DAYS = 8;

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
            ->where('recorded_at', '>=', Carbon::createFromTimestamp((int) ($from - self::GAP_THINNED)))
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

        $gap = $range === '7d' ? self::GAP_THINNED : self::GAP;
        $r = $this->engine->build($sources, $start, $count, $bucket, $gap, $now, $view, self::STALE);

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

    /**
     * Aclara el historial: las muestras de hace mas de FULL_HOURS se quedan en una
     * cada THIN_EVERY segundos por nodo (la primera de cada tramo) y se borra lo
     * anterior a KEEP_DAYS. La vista de 7 dias usa puntos de 1 h, asi que no pierde
     * nada visible; la tabla pasa de ~40.000 filas por nodo a ~6.000.
     *
     * Solo revisa lo que no se habia aclarado ya (se recuerda hasta donde llego).
     *
     * @return int filas borradas
     */
    public function compact(): int
    {
        $until = time() - self::FULL_HOURS * 3600;
        $done = (int) Cache::get('antiddos:metrics:thinned', 0);
        // Se empieza un tramo antes de lo ya aclarado para no partir un tramo de 5 min.
        $from = $done > 0 ? $done - self::THIN_EVERY : $until - (self::KEEP_DAYS + 7) * 86400;

        $deleted = ShieldMetric::query()
            ->where('recorded_at', '<', Carbon::createFromTimestamp(time() - self::KEEP_DAYS * 86400))
            ->delete();

        $last = [];
        $ids = [];

        $rows = ShieldMetric::query()->toBase()
            ->select(['id', 'host', 'recorded_at'])
            ->where('recorded_at', '>=', Carbon::createFromTimestamp($from))
            ->where('recorded_at', '<', Carbon::createFromTimestamp($until))
            ->orderBy('host')
            ->orderBy('recorded_at')
            ->cursor();

        foreach ($rows as $row) {
            $slot = intdiv((int) strtotime((string) $row->recorded_at), self::THIN_EVERY);

            if (($last[$row->host] ?? null) === $slot) {
                $ids[] = (int) $row->id;
            } else {
                $last[$row->host] = $slot;
            }
        }

        foreach (array_chunk($ids, 1000) as $chunk) {
            $deleted += ShieldMetric::query()->whereIn('id', $chunk)->delete();
        }

        Cache::forever('antiddos:metrics:thinned', $until);

        return $deleted;
    }
}
