<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Trafico «en vivo» (muestras cada segundo) de los ultimos 5 minutos.
 *
 * De donde salen las muestras:
 *   - Agente en la MISMA maquina que el panel: el servicio antiddos-live escribe
 *     /var/lib/antiddos/live.json cada segundo y el panel lo lee directamente.
 *   - Agente en OTRA maquina: envia cada muestra a POST /api/antiddos/live y se
 *     guarda en la cache del panel (Redis), sin tocar la base de datos.
 *
 * Los contadores son acumulados; aqui se convierten en velocidad (paquetes por
 * minuto) con las mismas reglas de veracidad que MetricsService: velocidad real
 * por tramo, huecos como huecos y reinicios de contador detectados.
 */
class LiveTraffic
{
    public const FILE = '/var/lib/antiddos/live.json';

    public const STEP = 1;            // segundos por punto
    public const WINDOW = 300;        // ventana mostrada (5 min)
    public const KEEP = 300;          // muestras guardadas por nodo
    public const GAP = 5;             // mas de esto entre muestras = hueco
    public const STALE = 6;           // sin muestras en este tiempo = datos viejos

    protected const KEYS = ['dropped', 'syn', 'tcp', 'udp'];

    /**
     * Guarda una muestra recibida por HTTP de un agente remoto.
     *
     * @param array<string, mixed> $data
     */
    public function push(array $data): bool
    {
        $host = (string) ($data['host'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $host)) {
            return false;
        }

        $int = static fn ($v): int => is_numeric($v) ? max(0, (int) $v) : 0;
        $t = $int($data['t'] ?? 0);

        // La hora del agente solo se acepta si es razonable; si no, la del panel.
        if (abs($t - time()) > 120) {
            $t = time();
        }

        $sample = ['t' => $t, 'dropped' => $int($data['dropped'] ?? 0), 'syn' => $int($data['syn'] ?? 0),
            'tcp' => $int($data['tcp'] ?? 0), 'udp' => $int($data['udp'] ?? 0), 'bans' => $int($data['bans'] ?? 0)];

        $key = 'antiddos:live:' . $host;
        $samples = (array) Cache::get($key, []);
        $samples[] = $sample;
        Cache::put($key, array_slice($samples, -self::KEEP), 300);

        $hosts = (array) Cache::get('antiddos:live:hosts', []);
        $hosts[$host] = $t;
        Cache::put('antiddos:live:hosts', $hosts, 3600);

        return true;
    }

    /**
     * Muestras por nodo: ['host' => [muestras...]].
     *
     * @return array<string, array<int, array<string, int>>>
     */
    protected function sources(?string $only): array
    {
        $out = [];

        // Agente local: archivo escrito por antiddos-live (puede no existir).
        $file = @file_get_contents(self::FILE);

        if ($file !== false) {
            $json = json_decode($file, true);

            if (is_array($json) && isset($json['host'], $json['samples']) && is_array($json['samples'])) {
                $out[(string) $json['host']] = $this->clean($json['samples']);
            }
        }

        // Agentes remotos: cache.
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

    /**
     * @param array<int, mixed> $samples
     * @return array<int, array<string, int>>
     */
    protected function clean(array $samples): array
    {
        $out = [];

        foreach ($samples as $s) {
            if (is_array($s) && isset($s['t']) && is_numeric($s['t'])) {
                $out[] = [
                    't' => (int) $s['t'], 'dropped' => (int) ($s['dropped'] ?? 0), 'syn' => (int) ($s['syn'] ?? 0),
                    'tcp' => (int) ($s['tcp'] ?? 0), 'udp' => (int) ($s['udp'] ?? 0), 'bans' => (int) ($s['bans'] ?? 0),
                ];
            }
        }

        usort($out, fn (array $a, array $b) => $a['t'] <=> $b['t']);

        return $out;
    }

    /**
     * @return array<string, mixed> mismo formato que MetricsService::series()
     */
    public function series(?string $host = null): array
    {
        $now = time();
        $start = intdiv($now - self::WINDOW, self::STEP) * self::STEP;
        $count = intdiv($now - $start, self::STEP) + 1;

        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = $start + $i * self::STEP;
        }

        $sources = $this->sources($host);
        $totals = array_fill_keys(self::KEYS, 0);
        $rate = [];
        $last = 0;
        $bansNow = 0;
        $intervals = 0;

        foreach ($sources as $name => $samples) {
            $prev = null;

            foreach ($samples as $s) {
                $last = max($last, $s['t']);

                if ($prev !== null && $s['t'] >= $start) {
                    $dt = $s['t'] - $prev['t'];
                    $idx = intdiv($s['t'] - $start, self::STEP);

                    if ($dt > 0 && $dt <= self::GAP && $idx >= 0 && $idx < $count) {
                        $intervals++;
                        $from = max(0, intdiv($prev['t'] - $start, self::STEP) + 1);

                        foreach (self::KEYS as $k) {
                            $delta = $s[$k] >= $prev[$k] ? $s[$k] - $prev[$k] : $s[$k];
                            $totals[$k] += $delta;
                            $perMin = $delta / $dt * 60;

                            // Si el bucle tardo mas de 1 s, los segundos intermedios llevan la
                            // misma velocidad media (es lo que de verdad ocurrio en ese tramo).
                            for ($j = $from; $j <= $idx; $j++) {
                                $rate[$name][$j][$k] = $perMin;
                            }
                        }
                    }
                }

                $prev = $s;
            }

            $end = end($samples);

            if ($end !== false && $now - $end['t'] <= self::STALE) {
                $bansNow += $end['bans'];
            }
        }

        $series = array_fill_keys(self::KEYS, []);
        $peak = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum = null;

            foreach ($rate as $perHost) {
                if (isset($perHost[$i])) {
                    foreach (self::KEYS as $k) {
                        $sum[$k] = ($sum[$k] ?? 0) + $perHost[$i][$k];
                    }
                }
            }

            foreach (self::KEYS as $k) {
                $series[$k][] = $sum === null ? null : round($sum[$k], 1);
            }

            if ($sum !== null) {
                $peak = max($peak, $sum['dropped']);
            }
        }

        return [
            'range' => 'live',
            'bucket' => self::STEP,
            'labels' => $labels,
            'series' => $series,
            'hosts' => array_keys($sources),
            'totals' => [
                'dropped' => $totals['dropped'],
                'syn' => $totals['syn'],
                'tcp' => $totals['tcp'],
                'udp' => $totals['udp'],
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
}
