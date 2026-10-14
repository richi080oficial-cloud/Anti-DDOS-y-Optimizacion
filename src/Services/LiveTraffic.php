<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;

class LiveTraffic
{
    public const FILE = '/var/lib/antiddos/live.json';

    public const STEP = 1;
    public const WINDOW = 300;
    public const KEEP = 300;
    public const GAP = 5.0;
    public const STALE = 6.0;

    public function __construct(protected TrafficEngine $engine)
    {
    }

    public function push(array $data): bool
    {
        $host = (string) ($data['host'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $host)) {
            return false;
        }

        $sample = $this->normalize($data);

        if (abs($sample['t'] - microtime(true)) > 120) {
            $sample['t'] = round(microtime(true), 3);
        }

        $key = 'antiddos:live:' . $host;
        $samples = (array) Cache::get($key, []);
        $samples[] = $sample;
        Cache::put($key, array_slice($samples, -self::KEEP), 300);

        $hosts = (array) Cache::get('antiddos:live:hosts', []);
        $hosts[$host] = $sample['t'];
        Cache::put('antiddos:live:hosts', $hosts, 3600);

        return true;
    }

    protected function normalize(array $s): array
    {
        $out = ['t' => is_numeric($s['t'] ?? null) ? round((float) $s['t'], 3) : round(microtime(true), 3)];

        foreach (array_merge(TrafficEngine::COUNTERS, ['bans']) as $k) {
            $out[$k] = isset($s[$k]) && is_numeric($s[$k]) ? max(0, (int) $s[$k]) : 0;
        }

        return $out;
    }

    protected function sources(?string $only): array
    {
        $out = [];

        $file = @file_get_contents(self::FILE);

        if ($file !== false) {
            $json = json_decode($file, true);

            if (is_array($json) && isset($json['host'], $json['samples']) && is_array($json['samples'])) {
                $out[(string) $json['host']] = $this->clean($json['samples']);
            }
        }

        foreach (array_keys((array) Cache::get('antiddos:live:hosts', [])) as $host) {
            $samples = (array) Cache::get('antiddos:live:' . $host, []);

            if ($samples !== []) {
                $out[(string) $host] = $this->clean($samples);
            }
        }

        if ($only !== null && $only !== '') {
            $out = array_intersect_key($out, [$only => true]);
        }

        return $out;
    }

    protected function clean(array $samples): array
    {
        $out = [];

        foreach ($samples as $s) {
            if (is_array($s) && isset($s['t']) && is_numeric($s['t'])) {
                $out[] = $this->normalize($s);
            }
        }

        usort($out, fn (array $a, array $b) => $a['t'] <=> $b['t']);

        return $out;
    }

    public function series(?string $host = null, string $view = 'packets'): array
    {
        $now = microtime(true);
        $start = (int) floor($now) - self::WINDOW;
        $count = self::WINDOW + 1;
        $sources = $this->sources($host);

        $r = $this->engine->build($sources, $start, $count, self::STEP, self::GAP, $now, $view, self::STALE);

        return [
            'range' => 'live',
            'view' => $view,
            'bucket' => self::STEP,
            'labels' => $r['labels'],
            'series' => $r['series'],
            'stats' => $r['stats'],
            'hosts' => array_keys($sources),
            'totals' => $r['totals'] + ['bans_now' => $r['bans_now']],
            'intervals' => $r['intervals'],
            'has_data' => $r['intervals'] > 0,
            'last_sample' => $r['last'],
            'stale' => $r['last'] === null || ($now - $r['last']) > self::STALE,
            'generated_at' => round($now, 3),
        ];
    }
}
