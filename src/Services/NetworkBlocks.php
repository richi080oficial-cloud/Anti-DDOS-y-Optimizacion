<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldNetBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

/**
 * Bloqueo de redes enteras: un operador (ASN) o un pais.
 *
 * Un atacante cambia de IP en segundos, pero no de operador: bloqueando todos
 * los rangos que anuncia su ASN se cortan tambien las IPs nuevas que coja.
 *
 * De donde salen los rangos (fuentes publicas, sin clave):
 *   - Operador: RIPEstat «announced-prefixes» (lo que el ASN ha anunciado en BGP
 *     en las ultimas 2 semanas, de cualquier region del mundo). Respaldo: whois de RADB.
 *   - Pais: RIPEstat «country-resource-list». Respaldo: ipdeny.com.
 * Solo IPv4 (el firewall de los nodos es IPv4). Los rangos se agregan (se unen los
 * contiguos) y se vuelven a descargar cada dia por si el operador anuncia otros.
 *
 * Donde se aplica:
 *   - Nodos: lineas «A <cidr>» del feed -> set vs_asn del firewall, que descarta todo
 *     lo que llega de esos rangos (a cualquier puerto) salvo la lista blanca.
 *   - Panel: ShieldGuard responde 403 sin tocar la BD (indice en cache troceado por /8).
 *
 * Red de seguridad: no se puede bloquear una red que contenga tu propia IP, la de
 * un nodo/el panel (seria el operador de tu propio servidor) o a Cloudflare.
 */
class NetworkBlocks
{
    protected const CUR_KEY = 'antiddos:netidx:cur';
    protected const VER_KEY = 'antiddos:netidx:ver';
    protected const FEED_KEY = 'antiddos:netfeed';

    /** A partir de cuantas IPs (una /10) hay que confirmar el bloqueo. */
    public const BIG_IPS = 4194304;

    /** Operadores que nunca se pueden bloquear: cortarian a todos los usuarios. */
    public const PROTECTED_ASNS = [
        13335 => 'Cloudflare',
        209242 => 'Cloudflare',
    ];

    /** @var array{v: int, octets: array<int, bool>, next: int}|null indice cargado en esta peticion */
    protected ?array $cur = null;

    /** @var array<int, array<string, array<int, mixed>>|null> trozos del indice cargados en esta peticion */
    protected array $buckets = [];

    // ── Entrada ───────────────────────────────────────────────────────────────

    /**
     * Numero de ASN a partir de lo que pegue el usuario: «AS199585», «199585» o
     * incluso «AS199585 · Operador / residencial». Null si no es un ASN publico.
     */
    public static function parseAsn(string $input): ?int
    {
        if (!preg_match('/^\s*(?:AS\s*)?(\d{1,10})\b/i', $input, $m)) {
            return null;
        }

        $n = (int) $m[1];
        // 0, AS_TRANS, documentacion/privados/reservados (64496-131071) y privados de 32 bits.
        $reserved = $n === 0 || $n === 23456 || ($n >= 64496 && $n <= 131071) || $n >= 4200000000;

        return $reserved ? null : $n;
    }

    public static function parseCountry(string $input): ?string
    {
        return preg_match('/^\s*([A-Za-z]{2})\s*$/', $input, $m) ? strtoupper($m[1]) : null;
    }

    /**
     * @return array{0: string, 1: string} [tipo, valor normalizado]
     */
    public function normalize(string $kind, string $input): array
    {
        if ($kind === 'asn') {
            $n = self::parseAsn($input);

            if ($n === null) {
                throw new \DomainException("«{$input}» no es un número de operador (ASN) válido. Ejemplo: AS199585.");
            }

            return ['asn', (string) $n];
        }

        if ($kind === 'country') {
            $cc = self::parseCountry($input);

            if ($cc === null) {
                throw new \DomainException("«{$input}» no es un código de país válido. Usa las 2 letras: PL, RU, CN…");
            }

            return ['country', $cc];
        }

        throw new \DomainException('Tipo de bloqueo no válido (usa «asn» o «country»).');
    }

    // ── Bloquear / desbloquear ────────────────────────────────────────────────

    /**
     * Bloquea un operador o un pais. $hours = null -> permanente.
     *
     * @throws \DomainException  si no se puede o no se debe bloquear
     * @throws \RuntimeException si no se pudieron descargar sus rangos
     */
    public function block(string $kind, string $input, ?int $hours, ?string $reason = null, ?int $userId = null, ?string $requestIp = null, bool $force = false): ShieldNetBlock
    {
        [$kind, $value] = $this->normalize($kind, $input);

        if ($kind === 'asn' && isset(self::PROTECTED_ASNS[(int) $value])) {
            throw new \DomainException("AS{$value} es " . self::PROTECTED_ASNS[(int) $value] . ': bloquearlo cortaría el acceso a todos los usuarios que entran por su red.');
        }

        $resolved = $this->resolve($kind, $value);
        $ranges = self::toRanges($resolved['prefixes']);
        $this->safetyCheck($ranges, $requestIp);

        $ips = self::countIps($ranges);
        $label = $kind === 'asn' ? "AS{$value}" . ($resolved['name'] ? " ({$resolved['name']})" : '') : "El país {$value}";

        if ($ips > self::BIG_IPS && !$force) {
            throw new \DomainException(sprintf(
                '%s abarca %s IPs: es una red muy grande y bloquearía también a muchos jugadores legítimos. Si estás seguro, marca «Bloquear aunque sea muy grande» (en consola: --force).',
                $label, number_format($ips, 0, ',', '.')
            ));
        }

        $block = ShieldNetBlock::query()->firstOrNew(['kind' => $kind, 'value' => $value]);
        $block->fill([
            'name' => $resolved['name'] ? mb_substr($resolved['name'], 0, 190) : $block->name,
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 190) : ($block->reason ?? 'Bloqueo manual'),
            'prefixes' => $resolved['prefixes'],
            'prefix_count' => count($resolved['prefixes']),
            'ip_count' => $ips,
            'source' => $resolved['source'],
            'user_id' => $userId,
            'expires_at' => $hours === null ? null : Carbon::now()->addHours(max(1, $hours)),
            'refreshed_at' => Carbon::now(),
        ]);
        $block->save();

        $this->flush();

        ShieldEvent::record('net-ban', sprintf(
            '%s bloqueado %s: %d rango(s), %s IPs (fuente: %s)%s',
            $block->label(), $hours === null ? 'permanentemente' : "{$hours} h", $block->prefix_count,
            number_format($ips, 0, ',', '.'), $resolved['source'], $block->reason ? " · {$block->reason}" : ''
        ), null, ['user_id' => $userId]);

        return $block;
    }

    public function unblock(string $kind, string $input): bool
    {
        [$kind, $value] = $this->normalize($kind, $input);

        $block = ShieldNetBlock::query()->where('kind', $kind)->where('value', $value)->first();

        if ($block === null) {
            return false;
        }

        $label = $block->label();
        $block->delete();
        $this->flush();

        ShieldEvent::record('net-unban', "{$label} desbloqueado");

        return true;
    }

    /** @return Collection<int, ShieldNetBlock> */
    public function active(): Collection
    {
        try {
            return ShieldNetBlock::query()
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
                ->orderBy('kind')->orderBy('value')
                ->get();
        } catch (\Throwable $e) {
            // Tabla aun sin migrar.
            return collect();
        }
    }

    /**
     * Vuelve a descargar los rangos de los bloqueos activos (el operador puede
     * anunciar rangos nuevos). Si una descarga falla se conservan los anteriores.
     *
     * @return array<string, string> etiqueta => resultado
     */
    public function refresh(?int $id = null): array
    {
        $out = [];

        foreach ($this->active() as $block) {
            if ($id !== null && $block->id !== $id) {
                continue;
            }

            try {
                $resolved = $this->resolve($block->kind, $block->value);
                $ranges = self::toRanges($resolved['prefixes']);
                $this->safetyCheck($ranges, null);

                $before = $block->prefix_count;
                $block->fill([
                    'prefixes' => $resolved['prefixes'],
                    'prefix_count' => count($resolved['prefixes']),
                    'ip_count' => self::countIps($ranges),
                    'source' => $resolved['source'],
                    'name' => $resolved['name'] ? mb_substr($resolved['name'], 0, 190) : $block->name,
                    'refreshed_at' => Carbon::now(),
                ])->save();

                $out[$block->label()] = "{$block->prefix_count} rango(s)" . ($before !== $block->prefix_count ? " (antes {$before})" : '');
            } catch (\Throwable $e) {
                $out[$block->label()] = 'sin cambios: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }

        $this->flush();

        return $out;
    }

    /** Borra los bloqueos caducados hace mas de un dia. */
    public function prune(): int
    {
        try {
            $n = ShieldNetBlock::query()->whereNotNull('expires_at')->where('expires_at', '<', Carbon::now()->subDay())->delete();
        } catch (\Throwable $e) {
            return 0;
        }

        $this->flush();

        return $n;
    }

    public function flush(): void
    {
        Cache::forget(self::CUR_KEY);
        Cache::forget(self::FEED_KEY);
        $this->cur = null;
        $this->buckets = [];
    }

    // ── Lo que se envia a los nodos ───────────────────────────────────────────

    /**
     * Union de los rangos de todos los bloqueos activos, agregada (lineas «A» del feed).
     *
     * @return array<int, string>
     */
    public function feedPrefixes(): array
    {
        $cached = Cache::get(self::FEED_KEY);

        if (is_array($cached) && ($cached['until'] === 0 || $cached['until'] > time())) {
            return $cached['prefixes'];
        }

        $ranges = [];
        $next = 0;

        foreach ($this->active() as $block) {
            if ($block->expires_at !== null) {
                $t = $block->expires_at->getTimestamp();
                $next = $next === 0 ? $t : min($next, $t);
            }

            array_push($ranges, ...self::toRanges((array) $block->prefixes));
        }

        $prefixes = self::toCidrs(self::aggregate($ranges));
        Cache::put(self::FEED_KEY, ['prefixes' => $prefixes, 'until' => $next], 600);

        return $prefixes;
    }

    /** Expresion para una regla de Cloudflare (WAF) que bloquea lo mismo en el borde. */
    public function cloudflareExpression(): ?string
    {
        $active = $this->active();
        $asns = $active->where('kind', 'asn')->pluck('value')->all();
        $ccs = $active->where('kind', 'country')->pluck('value')->all();
        $parts = [];

        if ($asns !== []) {
            $parts[] = '(ip.src.asnum in {' . implode(' ', $asns) . '})';
        }

        if ($ccs !== []) {
            $parts[] = '(ip.src.country in {"' . implode('" "', $ccs) . '"})';
        }

        return $parts === [] ? null : implode(' or ', $parts);
    }

    // ── Comprobacion rapida para el escudo web ────────────────────────────────

    /**
     * Si la IP pertenece a una red bloqueada devuelve su etiqueta; si no, null.
     * Sin SQL en el camino normal: un GET de cache y, si el primer octeto tiene
     * rangos bloqueados, otro GET con solo los rangos de ese /8.
     */
    public function match(string $ip): ?string
    {
        $n = ip2long($ip);

        if ($n === false) {
            return null; // IPv6: el bloqueo de redes es solo IPv4
        }

        $n &= 0xFFFFFFFF;
        $cur = $this->current();
        $octet = $n >> 24;

        if (!isset($cur['octets'][$octet])) {
            return null;
        }

        if (!array_key_exists($octet, $this->buckets)) {
            $this->buckets[$octet] = Cache::get("antiddos:netidx:{$cur['v']}:{$octet}");
        }

        $b = $this->buckets[$octet];

        if (!is_array($b)) {
            // El trozo se perdio de la cache: se reconstruye en la siguiente peticion.
            Cache::forget(self::CUR_KEY);

            return null;
        }

        $lo = 0;
        $hi = count($b['starts']) - 1;
        $idx = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($b['starts'][$mid] <= $n) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($idx < 0 || $b['pmax'][$idx] < $n) {
            return null;
        }

        for ($j = $idx; $j >= 0; $j--) {
            if ($b['starts'][$j] <= $n && $b['ends'][$j] >= $n) {
                return $b['labels'][$j];
            }
        }

        return null;
    }

    /**
     * @return array{v: int, octets: array<int, bool>, next: int}
     */
    protected function current(): array
    {
        $fresh = fn ($c) => is_array($c) && ($c['next'] === 0 || $c['next'] > time());

        if ($fresh($this->cur)) {
            return $this->cur;
        }

        $cur = Cache::get(self::CUR_KEY);

        if (!$fresh($cur)) {
            $cur = $this->rebuild();
        }

        return $this->cur = $cur;
    }

    /**
     * Reconstruye el indice: por cada /8 una lista ordenada de rangos con el maximo
     * acumulado de los finales (misma busqueda binaria que ReputationLists).
     *
     * @return array{v: int, octets: array<int, bool>, next: int}
     */
    protected function rebuild(): array
    {
        $version = (int) Cache::get(self::VER_KEY, 0) + 1;
        $buckets = [];
        $next = 0;

        foreach ($this->active() as $block) {
            if ($block->expires_at !== null) {
                $t = $block->expires_at->getTimestamp();
                $next = $next === 0 ? $t : min($next, $t);
            }

            $label = $block->label();

            foreach (self::toRanges((array) $block->prefixes) as [$s, $e]) {
                for ($o = $s >> 24; $o <= $e >> 24; $o++) {
                    $buckets[$o][] = [max($s, $o << 24), min($e, ($o << 24) | 0xFFFFFF), $label];
                }
            }
        }

        foreach ($buckets as $o => $rows) {
            usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);
            $data = ['starts' => [], 'ends' => [], 'pmax' => [], 'labels' => []];
            $max = -1;

            foreach ($rows as [$s, $e, $l]) {
                $max = max($max, $e);
                $data['starts'][] = $s;
                $data['ends'][] = $e;
                $data['pmax'][] = $max;
                $data['labels'][] = $l;
            }

            Cache::put("antiddos:netidx:{$version}:{$o}", $data, 172800);
        }

        $cur = ['v' => $version, 'octets' => array_fill_keys(array_keys($buckets), true), 'next' => $next];

        Cache::forever(self::VER_KEY, $version);
        Cache::put(self::CUR_KEY, $cur, 86400);

        return $cur;
    }

    // ── Descarga de rangos ────────────────────────────────────────────────────

    /**
     * @return array{prefixes: array<int, string>, name: string|null, source: string}
     */
    public function resolve(string $kind, string $value): array
    {
        if ($kind === 'asn') {
            $cidrs = $this->ripeAnnounced((int) $value);
            $source = 'RIPEstat';

            if ($cidrs === []) {
                $cidrs = $this->radb((int) $value);
                $source = 'RADB';
            }

            $name = $this->asnName((int) $value);
        } else {
            $cidrs = $this->ripeCountry($value);
            $source = 'RIPEstat';

            if ($cidrs === []) {
                $cidrs = $this->ipdeny($value);
                $source = 'ipdeny.com';
            }

            $name = $value;
        }

        $ranges = self::aggregate(self::toRanges($cidrs));

        if ($ranges === []) {
            throw new \RuntimeException($kind === 'asn'
                ? "No se encontró ningún rango IPv4 de AS{$value} (ni en RIPEstat ni en RADB). Comprueba el número o inténtalo en unos minutos."
                : "No se encontró ningún rango IPv4 del país {$value}. Comprueba el código o inténtalo en unos minutos.");
        }

        return ['prefixes' => self::toCidrs($ranges), 'name' => $name, 'source' => $source];
    }

    /** @return array<int, string> */
    protected function ripeAnnounced(int $asn): array
    {
        try {
            $r = Http::timeout(25)->acceptJson()
                ->get('https://stat.ripe.net/data/announced-prefixes/data.json', ['resource' => "AS{$asn}", 'sourceapp' => 'antiddos-pterodactyl']);

            if (!$r->successful()) {
                return [];
            }

            return array_map(fn ($p) => (string) ($p['prefix'] ?? ''), (array) $r->json('data.prefixes', []));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Respaldo: objetos «route» registrados en RADB para ese origen (whois, puerto 43). */
    protected function radb(int $asn): array
    {
        $fp = @fsockopen('whois.radb.net', 43, $errno, $errstr, 10);

        if (!$fp) {
            return [];
        }

        stream_set_timeout($fp, 15);
        fwrite($fp, "-K -i origin AS{$asn}\r\n");

        $body = '';
        $started = time();

        while (!feof($fp) && strlen($body) < 4000000 && time() - $started < 25) {
            $chunk = fread($fp, 8192);

            if ($chunk === false || ($chunk === '' && (stream_get_meta_data($fp)['timed_out'] ?? false))) {
                break;
            }

            $body .= $chunk;
        }

        fclose($fp);

        preg_match_all('/^route:\s*(\S+)/mi', $body, $m);

        return $m[1] ?? [];
    }

    protected function asnName(int $asn): ?string
    {
        return Cache::remember("antiddos:asname:{$asn}", 604800, function () use ($asn) {
            try {
                $r = Http::timeout(10)->acceptJson()
                    ->get('https://stat.ripe.net/data/as-overview/data.json', ['resource' => "AS{$asn}", 'sourceapp' => 'antiddos-pterodactyl']);
                $holder = trim((string) $r->json('data.holder', ''));

                return $holder !== '' ? $holder : null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /** @return array<int, string> */
    protected function ripeCountry(string $cc): array
    {
        try {
            $r = Http::timeout(40)->acceptJson()
                ->get('https://stat.ripe.net/data/country-resource-list/data.json', ['resource' => $cc, 'v4_format' => 'prefix', 'sourceapp' => 'antiddos-pterodactyl']);

            return $r->successful() ? array_map('strval', (array) $r->json('data.resources.ipv4', [])) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int, string> */
    protected function ipdeny(string $cc): array
    {
        try {
            $r = Http::timeout(30)->get('https://www.ipdeny.com/ipblocks/data/aggregated/' . strtolower($cc) . '-aggregated.zone');

            return $r->successful() ? (preg_split('/\s+/', trim((string) $r->body())) ?: []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Seguridad ─────────────────────────────────────────────────────────────

    /**
     * @param array<int, array{0: int, 1: int}> $ranges
     */
    protected function safetyCheck(array $ranges, ?string $requestIp): void
    {
        $whitelist = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));

        if ($requestIp !== null && self::rangesContain($ranges, $requestIp) && !IpMatcher::inList($requestIp, $whitelist)) {
            throw new \DomainException("Tu propia IP ({$requestIp}) está dentro de esta red: te dejarías fuera del panel. Si aun así quieres bloquearla, añade antes tu IP a la lista blanca.");
        }

        foreach (app(SafeIps::class)->infrastructure() as $ip) {
            if (self::rangesContain($ranges, $ip)) {
                throw new \DomainException("La IP {$ip} de tu propia infraestructura (un nodo o el panel) está dentro de esta red: es el operador de tu propio servidor. No se bloquea.");
            }
        }

        $cloudflare = self::toRanges(IpMatcher::CLOUDFLARE_V4);

        foreach ($ranges as [$s, $e]) {
            foreach ($cloudflare as [$cs, $ce]) {
                if ($s <= $ce && $cs <= $e) {
                    throw new \DomainException('Esta red incluye IPs de Cloudflare: bloquearla cortaría el panel a todos los usuarios que entran por Cloudflare.');
                }
            }
        }
    }

    // ── Aritmetica de rangos IPv4 (enteros de 64 bits) ────────────────────────

    /**
     * @param array<int, string> $cidrs
     * @return array<int, array{0: int, 1: int}>
     */
    public static function toRanges(array $cidrs): array
    {
        $out = [];

        foreach ($cidrs as $cidr) {
            if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', trim((string) $cidr), $m)) {
                continue; // IPv6 u otra cosa
            }

            $ip = ip2long($m[1]);
            $bits = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 32;

            if ($ip === false || $bits > 32 || $bits < 1) {
                continue; // nunca se acepta 0.0.0.0/0
            }

            $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            $start = ($ip & 0xFFFFFFFF) & $mask;
            $out[] = [$start, $start + (1 << (32 - $bits)) - 1];
        }

        return $out;
    }

    /**
     * Ordena y une los rangos que se solapan o se tocan.
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return array<int, array{0: int, 1: int}>
     */
    public static function aggregate(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $out = [];

        foreach ($ranges as [$s, $e]) {
            $last = count($out) - 1;

            if ($last >= 0 && $s <= $out[$last][1] + 1) {
                $out[$last][1] = max($out[$last][1], $e);
            } else {
                $out[] = [$s, $e];
            }
        }

        return $out;
    }

    /**
     * Convierte rangos en la lista minima de bloques CIDR, como mucho de /8 (el
     * agente de nodo rechaza por seguridad cualquier bloque mas grande).
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return array<int, string>
     */
    public static function toCidrs(array $ranges): array
    {
        $out = [];

        foreach ($ranges as [$s, $e]) {
            while ($s <= $e) {
                // Bloque mas grande alineado en $s que no se pasa de $e (maximo una /8).
                $size = min(1 << 24, $s === 0 ? 1 << 32 : ($s & -$s));

                while ($s + $size - 1 > $e) {
                    $size >>= 1;
                }

                $bits = 32;

                for ($t = $size; $t > 1; $t >>= 1) {
                    $bits--;
                }

                $out[] = long2ip($s) . '/' . $bits;
                $s += $size;
            }
        }

        return $out;
    }

    /** @param array<int, array{0: int, 1: int}> $ranges */
    public static function countIps(array $ranges): int
    {
        return array_sum(array_map(fn (array $r) => $r[1] - $r[0] + 1, $ranges));
    }

    /** @param array<int, array{0: int, 1: int}> $ranges */
    public static function rangesContain(array $ranges, string $ip): bool
    {
        $n = ip2long($ip);

        if ($n === false) {
            return false;
        }

        $n &= 0xFFFFFFFF;

        foreach ($ranges as [$s, $e]) {
            if ($n >= $s && $n <= $e) {
                return true;
            }
        }

        return false;
    }
}
