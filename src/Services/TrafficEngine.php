<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

/**
 * Motor unico que convierte muestras de contadores ACUMULADOS en series de
 * velocidad (por segundo). Lo usan el modo «en vivo» y el historial, asi las
 * dos graficas calculan exactamente igual.
 *
 * Reglas de precision:
 *   - Cada par de muestras consecutivas da una velocidad real: incremento del
 *     contador / segundos reales transcurridos (con milisegundos).
 *   - Esa velocidad se reparte entre los puntos de la grafica que el tramo
 *     cubre, ponderando por el tiempo que cae en cada punto. Un punto a medias
 *     (el ultimo) usa solo el tiempo medido: no parece una caida.
 *   - Los tramos mas largos que $gap segundos se descartan: son huecos (null),
 *     nunca picos que concentran minutos u horas en un punto.
 *   - Un contador que baja es un reinicio (reglas reaplicadas): el incremento
 *     es el valor nuevo.
 *   - Con varios nodos se suman las velocidades de cada nodo.
 */
class TrafficEngine
{
    /** Contadores acumulados que informa el agente. */
    public const COUNTERS = [
        'tcp', 'udp', 'syn', 'tcp_b', 'udp_b', 'd_ban', 'd_mal', 'd_icmp', 'd_syn', 'd_udp', 'd_conn', 'd_web', 'd_bytes',
        'rx_b', 'rx_p', 'tx_b', 'tx_p',   // tarjeta de red completa (RX/TX, como la grafica de OVH)
    ];

    /** Motivos de frenado (todos paquetes). */
    public const REASONS = ['d_ban', 'd_mal', 'd_icmp', 'd_syn', 'd_udp', 'd_conn', 'd_web'];

    public const VIEWS = ['network', 'packets', 'reasons'];

    /**
     * @param array<string, array<int, array<string, int|float>>> $sources muestras por nodo (ordenadas por 't')
     * @return array{labels: array<int, int>, series: array<string, array<int, float|null>>, stats: array<string, array<string, mixed>>, totals: array<string, int>, intervals: int, last: float|null, bans_now: int}
     */
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

        // Velocidad por punto (suma de nodos), por segundo.
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

    /**
     * Selecciona y deriva las series de la vista pedida.
     *
     * @param array<int, array<string, float>|null> $perSecond
     * @return array<string, array<int, float|null>>
     */
    protected function shape(array $perSecond, string $view): array
    {
        $out = [];

        $push = function (string $name, callable $fn) use (&$out, $perSecond) {
            $out[$name] = array_map(fn ($c) => $c === null ? null : round($fn($c), 3), $perSecond);
        };

        $dropped = fn (array $c) => $c['d_ban'] + $c['d_mal'] + $c['d_icmp'] + $c['d_syn'] + $c['d_udp'] + $c['d_conn'] + $c['d_web'];

        switch ($view) {
            case 'network':
                // Ancho de banda de la interfaz (Mbit/s), como la grafica de OVH: bajada (RX) y subida (TX),
                // mas lo que frena el firewall.
                $push('rx', fn ($c) => $c['rx_b'] * 8 / 1e6);
                $push('tx', fn ($c) => $c['tx_b'] * 8 / 1e6);
                $push('dropped', fn ($c) => $c['d_bytes'] * 8 / 1e6);
                break;

            case 'reasons':
                foreach (self::REASONS as $reason) {
                    $push($reason, fn ($c) => $c[$reason]);
                }
                break;

            default: // packets (por segundo)
                $push('rx', fn ($c) => $c['rx_p']);      // todos los paquetes que entran por la interfaz
                $push('tcp', fn ($c) => $c['tcp']);      // TCP hacia puertos protegidos
                $push('udp', fn ($c) => $c['udp']);      // UDP hacia puertos protegidos
                $push('syn', fn ($c) => $c['syn']);      // conexiones nuevas por segundo
                $push('dropped', $dropped);
        }

        return $out;
    }

    /**
     * @param array<int, float|null> $values
     * @param array<int, int> $labels
     * @return array<string, mixed>
     */
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
