<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;

class MetricsService
{
    public const RANGES = [
        '1h' => [3600, 30],
        '6h' => [21600, 300],
        '24h' => [86400, 900],
        '7d' => [604800, 3600],
    ];

    public const GAP = 150.0;

    public const GAP_THINNED = 420.0;

    public const FULL_HOURS = 26;

    public const THIN_EVERY = 300;

    public const KEEP_DAYS = 8;

    public const STALE = 120.0;

    protected const COLUMNS = [
        'tcp_pkts' => 'tcp', 'udp_pkts' => 'udp', 'syn' => 'syn', 'tcp_bytes' => 'tcp_b', 'udp_bytes' => 'udp_b',
        'd_ban' => 'd_ban', 'd_mal' => 'd_mal', 'd_icmp' => 'd_icmp', 'd_syn' => 'd_syn', 'd_udp' => 'd_udp',
        'd_conn' => 'd_conn', 'd_web' => 'd_web', 'drop_bytes' => 'd_bytes',
        'rx_bytes' => 'rx_b', 'rx_pkts' => 'rx_p', 'tx_bytes' => 'tx_b', 'tx_pkts' => 'tx_p',
    ];

    public function __construct(protected TrafficEngine $engine)
    {
    }

    public function series(string $range = '1h', ?string $host = null, string $view = 'packets'): array
    {
        $range = isset(self::RANGES[$range]) ? $range : '1h';

        return Cache::remember(
            'antiddos:metrics:' . md5($range . '|' . $host . '|' . $view),
            $range === '1h' ? 10 : 60,
            fn () => $this->build($range, $host, $view)
        );
    }

    protected function build(string $range, ?string $host, string $view): array
    {
        [$window, $bucket] = self::RANGES[$range];

        $now = microtime(true);
        $from = (int) floor($now) - $window;
        $start = intdiv($from, $bucket) * $bucket;
        $count = intdiv((int) floor($now) - $start, $bucket) + 1;

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

    public function compact(): int
    {
        $until = time() - self::FULL_HOURS * 3600;
        $done = (int) Cache::get('antiddos:metrics:thinned', 0);
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
