<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

class TrafficEngine
{
    public const COUNTERS = [
        'tcp', 'udp', 'syn', 'tcp_b', 'udp_b', 'd_ban', 'd_mal', 'd_icmp', 'd_syn', 'd_udp', 'd_conn', 'd_web', 'd_bytes',
        'rx_b', 'rx_p', 'tx_b', 'tx_p',
    ];

    public const REASONS = ['d_ban', 'd_mal', 'd_icmp', 'd_syn', 'd_udp', 'd_conn', 'd_web'];

    public const VIEWS = ['network', 'packets', 'reasons'];

    public function build(array $sources, int $start, int $count, int $step, float $gap, float $now, string $view, float $staleAfter): array
    {
        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = $start + $i * $step;
        }

        $end = $start + $count * $step;
        $acc = [];
        $totals = array_fill_keys(self::COUNTERS, 0);
        $intervals = 0;
        $last = 0.0;
        $bansNow = 0;

        foreach ($sources as $host => $samples) {
            $prev = null;

            foreach ($samples as $s) {
                $last = max($last, (float) $s['t']);

                if ($prev !== null) {
                    $dt = (float) $s['t'] - (float) $prev['t'];

                    if ($dt > 0 && $dt <= $gap && $s['t'] >= $start && $prev['t'] < $end) {
                        $intervals++;
                        $rates = [];

                        foreach (self::COUNTERS as $k) {
                            $delta = $s[$k] >= $prev[$k] ? $s[$k] - $prev[$k] : $s[$k];
                            $totals[$k] += $delta;
                            $rates[$k] = $delta / $dt;
                        }

                        $from = max(0, (int) floor(((float) $prev['t'] - $start) / $step));
                        $to = min($count - 1, (int) floor(((float) $s['t'] - $start) / $step));

                        for ($idx = $from; $idx <= $to; $idx++) {
                            $b0 = $start + $idx * $step;
                            $overlap = min((float) $s['t'], $b0 + $step) - max((float) $prev['t'], $b0);

                            if ($overlap <= 0) {
                                continue;
                            }

                            $acc[$host][$idx]['w'] = ($acc[$host][$idx]['w'] ?? 0) + $overlap;

                            foreach (self::COUNTERS as $k) {
                                $acc[$host][$idx][$k] = ($acc[$host][$idx][$k] ?? 0) + $rates[$k] * $overlap;
                            }
                        }
                    }
                }

                $prev = $s;
            }

            $tail = end($samples);

            if ($tail !== false && $now - (float) $tail['t'] <= $staleAfter) {
                $bansNow += (int) ($tail['bans'] ?? 0);
            }
        }

        $perSecond = [];

        for ($i = 0; $i < $count; $i++) {
            $sum = null;

            foreach ($acc as $perHost) {
                if (!isset($perHost[$i]) || $perHost[$i]['w'] <= 0) {
                    continue;
                }

                foreach (self::COUNTERS as $k) {
                    $sum[$k] = ($sum[$k] ?? 0) + $perHost[$i][$k] / $perHost[$i]['w'];
                }
            }

            $perSecond[$i] = $sum;
        }

        $series = $this->shape($perSecond, $view);
        $stats = [];

        foreach ($series as $name => $values) {
            $stats[$name] = $this->stats($values, $labels);
        }

        return [
            'labels' => $labels,
            'series' => $series,
            'stats' => $stats,
            'totals' => $totals,
            'intervals' => $intervals,
            'last' => $last > 0 ? $last : null,
            'bans_now' => $bansNow,
        ];
    }

    protected function shape(array $perSecond, string $view): array
    {
        $out = [];

        $push = function (string $name, callable $fn) use (&$out, $perSecond) {
            $out[$name] = array_map(fn ($c) => $c === null ? null : round($fn($c), 3), $perSecond);
        };

        $dropped = fn (array $c) => $c['d_ban'] + $c['d_mal'] + $c['d_icmp'] + $c['d_syn'] + $c['d_udp'] + $c['d_conn'] + $c['d_web'];

        switch ($view) {
            case 'network':
                $push('rx', fn ($c) => $c['rx_b'] * 8 / 1e6);
                $push('tx', fn ($c) => $c['tx_b'] * 8 / 1e6);
                $push('dropped', fn ($c) => $c['d_bytes'] * 8 / 1e6);
                break;

            case 'reasons':
                foreach (self::REASONS as $reason) {
                    $push($reason, fn ($c) => $c[$reason]);
                }
                break;

            default:
                $push('rx', fn ($c) => $c['rx_p']);
                $push('tcp', fn ($c) => $c['tcp']);
                $push('udp', fn ($c) => $c['udp']);
                $push('syn', fn ($c) => $c['syn']);
                $push('dropped', $dropped);
        }

        return $out;
    }

    protected function stats(array $values, array $labels): array
    {
        $known = array_filter($values, fn ($v) => $v !== null);

        if ($known === []) {
            return ['now' => null, 'avg' => null, 'peak' => null, 'peak_t' => null, 'min' => null];
        }

        $peakIdx = array_keys($known, max($known))[0];
        $lastIdx = array_key_last($known);

        return [
            'now' => $known[$lastIdx],
            'avg' => round(array_sum($known) / count($known), 3),
            'peak' => $known[$peakIdx],
            'peak_t' => $labels[$peakIdx],
            'min' => min($known),
        ];
    }
}
