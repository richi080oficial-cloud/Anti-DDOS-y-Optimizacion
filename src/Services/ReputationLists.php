<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Listas publicas de reputacion de IPs (solo IPv4), descargadas una vez al dia y
 * guardadas en la cache del panel como rangos ordenados para buscar rapido.
 *
 * Fuentes (gratuitas y publicas):
 *   - Blocklist.de: IPs que han atacado servidores en las ultimas horas.
 *   - Spamhaus DROP: bloques de red secuestrados o controlados por criminales.
 *   - FireHOL Level 1: agregado de listas de atacantes confirmados (sin falsos positivos
 *     conocidos: es la lista pensada para bloquear sin riesgo).
 *   - CINS Army: IPs con actividad maliciosa detectada por sensores.
 *   - Tor: nodos de salida (no es un ataque por si mismo; se marca como «anonimizador»).
 */
class ReputationLists
{
    /** id => [nombre, url, tipo: attack|anonymizer] */
    public const SOURCES = [
        'blocklist_de' => ['Blocklist.de (ataques recientes)', 'https://lists.blocklist.de/lists/all.txt', 'attack'],
        'spamhaus_drop' => ['Spamhaus DROP (redes de criminales)', 'https://www.spamhaus.org/drop/drop.txt', 'attack'],
        'firehol_l1' => ['FireHOL Level 1 (atacantes confirmados)', 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset', 'attack'],
        'cins' => ['CINS Army (actividad maliciosa)', 'https://cinsscore.com/list/ci-badguys.txt', 'attack'],
        'tor' => ['Tor (nodo de salida)', 'https://check.torproject.org/torbulkexitlist', 'anonymizer'],
    ];

    protected const TTL = 60 * 60 * 24 * 3;   // se conservan 3 dias si la descarga siguiente falla

    /** @var array<string, array<string, mixed>|false> listas ya leidas de la cache en este proceso */
    protected static array $loaded = [];

    /**
     * Descarga todas las listas. Cada una es independiente: un fallo no borra la
     * copia anterior.
     *
     * @return array<string, array{ok: bool, count: int, error: string|null}>
     */
    public function refresh(): array
    {
        $out = [];

        foreach (self::SOURCES as $id => [$name, $url]) {
            try {
                $response = Http::timeout(20)->withHeaders(['User-Agent' => 'AntiDDoS-Pterodactyl/1.0'])->get($url);

                if (!$response->successful()) {
                    throw new \RuntimeException('HTTP ' . $response->status());
                }

                $ranges = $this->parse((string) $response->body());

                if ($ranges === []) {
                    throw new \RuntimeException('lista vacía');
                }

                Cache::put("antiddos:rep:{$id}", ['at' => time()] + $this->index($ranges), self::TTL);
                Cache::put("antiddos:rep:meta:{$id}", ['at' => time(), 'count' => count($ranges)], self::TTL);
                unset(self::$loaded[$id]);
                $out[$id] = ['ok' => true, 'count' => count($ranges), 'error' => null];
            } catch (\Throwable $e) {
                $out[$id] = ['ok' => false, 'count' => 0, 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        Cache::forever('antiddos:rep:refreshed', time());

        return $out;
    }

    /**
     * Listas en las que aparece la IP: id => nombre.
     *
     * @return array<string, array{name: string, kind: string}>
     */
    public function match(string $ip): array
    {
        $n = ip2long($ip);

        if ($n === false) {
            return [];
        }

        $n = $n & 0xFFFFFFFF;
        $found = [];

        foreach (self::SOURCES as $id => [$name, , $kind]) {
            // Cada lista ocupa varios MB: se lee de la cache una sola vez por proceso.
            $data = self::$loaded[$id] ??= Cache::get("antiddos:rep:{$id}") ?? false;

            if (is_array($data) && $this->contains($data, $n)) {
                $found[$id] = ['name' => $name, 'kind' => $kind];
            }
        }

        return $found;
    }

    /**
     * Estado de cada lista para mostrarlo en el admin.
     *
     * @return array<string, array{name: string, loaded: bool, count: int, updated_at: int|null}>
     */
    public function status(): array
    {
        $out = [];

        foreach (self::SOURCES as $id => [$name]) {
            $meta = $this->meta($id);
            $out[$id] = [
                'name' => $name,
                'loaded' => $meta !== null,
                'count' => $meta['count'] ?? 0,
                'updated_at' => $meta['at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Resumen pequeno de una lista (fecha y entradas) sin cargar la lista entera.
     *
     * @return array{at: int|null, count: int}|null
     */
    protected function meta(string $id): ?array
    {
        $meta = Cache::get("antiddos:rep:meta:{$id}");

        if (is_array($meta)) {
            return $meta;
        }

        // Listas descargadas por una version anterior (sin resumen): se crea una vez.
        $data = Cache::get("antiddos:rep:{$id}");

        if (!is_array($data)) {
            return null;
        }

        $meta = ['at' => $data['at'] ?? null, 'count' => count($data['starts'] ?? [])];
        Cache::put("antiddos:rep:meta:{$id}", $meta, self::TTL);

        return $meta;
    }

    public function anyLoaded(): bool
    {
        return collect($this->status())->contains('loaded', true);
    }

    /**
     * @return array<int, array{0: int, 1: int}> rangos [inicio, fin] IPv4
     */
    protected function parse(string $body): array
    {
        $ranges = [];

        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $line = trim(preg_split('/[;#]/', $line, 2)[0] ?? '');

            if ($line === '') {
                continue;
            }

            $token = preg_split('/\s+/', $line)[0];

            if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', $token, $m)) {
                continue;
            }

            $start = ip2long($m[1]);
            $bits = isset($m[2]) ? (int) $m[2] : 32;

            if ($start === false || $bits > 32) {
                continue;
            }

            $start = $start & 0xFFFFFFFF;
            $size = $bits === 0 ? 0xFFFFFFFF + 1 : 1 << (32 - $bits);
            $start = $start & ($bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
            $ranges[] = [$start, $start + $size - 1];
        }

        return $ranges;
    }

    /**
     * Ordena por inicio y precalcula el maximo acumulado de los finales: asi un
     * punto esta en algun rango si el maximo final de los rangos que empiezan
     * antes o en el es >= que el.
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return array{starts: array<int, int>, pmax: array<int, int>}
     */
    protected function index(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $starts = [];
        $pmax = [];
        $max = -1;

        foreach ($ranges as [$from, $to]) {
            $max = max($max, $to);
            $starts[] = $from;
            $pmax[] = $max;
        }

        return ['starts' => $starts, 'pmax' => $pmax];
    }

    /**
     * @param array{starts: array<int, int>, pmax: array<int, int>} $data
     */
    protected function contains(array $data, int $n): bool
    {
        $lo = 0;
        $hi = count($data['starts']) - 1;
        $idx = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($data['starts'][$mid] <= $n) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $idx >= 0 && $data['pmax'][$idx] >= $n;
    }
}
