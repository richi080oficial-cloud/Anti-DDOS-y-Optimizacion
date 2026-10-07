<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

/**
 * Origen y reputacion de una IP, con veredicto explicado.
 *
 * Datos que se combinan (todo publico):
 *   - Origen: pais, ciudad, proveedor (ISP) y ASN, de ipwho.is (y ipapi.co como respaldo).
 *   - Reputacion: listas publicas de atacantes (ReputationLists) y, si se configura una
 *     clave gratuita, el indice de abuso de AbuseIPDB.
 *   - Tipo de red: residencial, hosting/servidor o VPN/proxy (heuristica por nombre del
 *     proveedor; se indica como «probable»).
 *
 * Veredictos:
 *   malicious  - aparece en listas de atacantes (o AbuseIPDB >= 75): bloquear es seguro.
 *   suspicious - anonimizador (Tor), servidor/VPN con muchas conexiones o abuso medio.
 *   clean      - sin listados y red residencial: parece un jugador normal, NO bloquear.
 *   unknown    - no se pudo consultar.
 *
 * Privacidad: solo se envia la IP a los servicios de consulta. Se puede desactivar
 * en Proteccion (entonces no se consulta nada y no hay veredictos).
 */
class IpIntel
{
    protected const TTL = 21600;       // 6 h
    protected const TTL_FAIL = 120;    // 2 min si no se ha podido consultar el origen: se reintenta enseguida
    protected const MAX_PER_REQUEST = 5;

    /** @var array<int, string> */
    protected static array $queued = [];

    public function __construct(protected ReputationLists $lists)
    {
    }

    public function enabled(): bool
    {
        return (bool) ShieldSetting::read('intel_enabled', true);
    }

    /**
     * Devuelve lo que haya en cache sin esperar. Si no hay nada, encola la
     * consulta para despues de responder y devuelve «pending».
     *
     * @return array<string, mixed>
     */
    public function peek(string $ip): array
    {
        if (!$this->enabled()) {
            return ['status' => 'disabled', 'verdict' => 'unknown', 'verdict_text' => 'Consulta de reputación desactivada'];
        }

        if (IpMatcher::isInternal($ip)) {
            return ['status' => 'internal', 'verdict' => 'clean', 'verdict_text' => 'IP interna', 'reasons' => ['Es una IP privada o reservada.']];
        }

        $cached = Cache::get($this->key($ip));

        if (is_array($cached)) {
            return $cached;
        }

        if (!$this->lists->anyLoaded() && Cache::add('antiddos:rep:autorefresh', 1, 900)) {
            app()->terminating(function () {
                try {
                    $this->lists->refresh();
                } catch (\Throwable $e) {
                    // Se reintenta en 15 minutos.
                }
            });
        }

        if (count(self::$queued) < self::MAX_PER_REQUEST && Cache::add($this->key($ip, 'q'), 1, 90)) {
            self::$queued[] = $ip;
            app()->terminating(function () use ($ip) {
                try {
                    $this->lookup($ip);
                } catch (\Throwable $e) {
                    // Una consulta fallida nunca debe afectar al panel.
                }
            });
        }

        return ['status' => 'pending', 'verdict' => 'unknown', 'verdict_text' => 'Consultando…'];
    }

    /**
     * Consulta completa (bloqueante). La usan la cola posterior a la respuesta y la consola.
     *
     * @return array<string, mixed>
     */
    public function lookup(string $ip, bool $force = false, bool $autoBlock = true): array
    {
        if (!$force && is_array($cached = Cache::get($this->key($ip)))) {
            return $cached;
        }

        if (IpMatcher::isInternal($ip)) {
            return ['status' => 'internal', 'verdict' => 'clean', 'verdict_text' => 'IP interna', 'reasons' => []];
        }

        $geo = $this->geo($ip);
        $matches = $this->lists->match($ip);
        $abuse = $this->abuse($ip);

        $intel = $this->assess($ip, $geo, $matches, $abuse);

        Cache::put($this->key($ip), $intel, $geo === null ? self::TTL_FAIL : self::TTL);

        // Bloqueo automatico (si esta activado): al instante, sin esperar a la pasada del minuto.
        if ($autoBlock && ($intel['verdict'] ?? '') === 'malicious') {
            try {
                app(AutoBlocker::class)->maybeBlock($ip, $intel);
            } catch (\Throwable $e) {
                // Un fallo al bloquear nunca debe romper la consulta.
            }
        }

        return $intel;
    }

    /** Repite la consulta de una IP ignorando lo guardado (boton «Reintentar»). */
    public function recheck(string $ip): array
    {
        Cache::forget($this->key($ip));
        Cache::forget($this->key($ip, 'q'));
        Cache::forget($this->key($ip, 'abuse'));
        foreach (['ripe', 'ipwho', 'freeipapi', 'geojs', 'ripegeo', 'ipapi'] as $provider) {
            Cache::forget($this->coolKey($provider));
        }

        return $this->lookup($ip, true);
    }

    /** Lo que haya en cache para la IP (sin consultar nada), o null. */
    public function cached(string $ip): ?array
    {
        $cached = Cache::get($this->key($ip));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, mixed>|null $geo
     * @param array<string, array{name: string, kind: string}> $matches
     * @return array<string, mixed>
     */
    protected function assess(string $ip, ?array $geo, array $matches, ?int $abuse): array
    {
        $attackLists = array_filter($matches, fn ($m) => $m['kind'] === 'attack');
        $tor = array_filter($matches, fn ($m) => $m['kind'] === 'anonymizer');
        $type = $geo['type'] ?? 'unknown';

        $reasons = [];
        $verdict = 'clean';

        if ($attackLists !== []) {
            $verdict = 'malicious';
            $reasons[] = 'Aparece en ' . count($attackLists) . ' lista(s) pública(s) de atacantes: ' . implode(', ', array_column($attackLists, 'name')) . '.';
        }

        if ($abuse !== null && $abuse >= 75) {
            $verdict = 'malicious';
            $reasons[] = "AbuseIPDB le da un índice de abuso de {$abuse} % (muchos reportes recientes).";
        } elseif ($abuse !== null && $abuse >= 25) {
            $verdict = $verdict === 'clean' ? 'suspicious' : $verdict;
            $reasons[] = "AbuseIPDB le da un índice de abuso moderado ({$abuse} %).";
        }

        if ($tor !== []) {
            $verdict = $verdict === 'clean' ? 'suspicious' : $verdict;
            $reasons[] = 'Es un nodo de salida de Tor (tráfico anónimo): no es un ataque por sí mismo, pero un jugador normal rara vez entra así.';
        }

        if (in_array($type, ['hosting', 'vpn'], true) && $verdict === 'clean') {
            $verdict = 'suspicious';
            $reasons[] = $type === 'hosting'
                ? 'Procede de un proveedor de servidores/centro de datos (probable): los bots de Minecraft suelen salir de ahí; un jugador normal, casi nunca.'
                : 'Procede de una VPN o proxy (probable).';
        }

        if ($verdict === 'clean') {
            if (!$this->lists->anyLoaded()) {
                // Sin listas no se puede afirmar que sea normal: seria una conclusion falsa.
                $verdict = 'unknown';
                $reasons[] = 'Las listas públicas de reputación aún no se han descargado, así que no se puede valorar. Se descargarán en unos segundos.';
            } elseif ($geo === null) {
                $verdict = 'unknown';
                $reasons[] = 'No se pudo consultar el origen (servicio no disponible). Sin listados en las bases de atacantes.';
            } else {
                $reasons[] = 'Sin listados en bases de atacantes y red de tipo residencial/operador: parece un jugador normal. No la bloquees.';
            }
        }

        $text = [
            'malicious' => 'Maliciosa',
            'suspicious' => 'Sospechosa',
            'clean' => 'Normal',
            'unknown' => 'Sin datos',
        ][$verdict];

        return [
            // «partial» = no se pudo consultar el origen (los servicios no respondieron): se reintenta solo.
            'status' => $geo === null ? 'partial' : 'ok',
            'ip' => $ip,
            'verdict' => $verdict,
            'verdict_text' => $text,
            'reasons' => $reasons,
            'country' => $geo['country'] ?? null,
            'cc' => $geo['cc'] ?? null,
            'flag' => $geo['flag'] ?? null,
            'region' => $geo['region'] ?? null,
            'city' => $geo['city'] ?? null,
            'asn' => $geo['asn'] ?? null,
            'org' => $geo['org'] ?? null,
            'type' => $type,
            'lists' => array_values(array_column($matches, 'name')),
            'abuse' => $abuse,
            'checked_at' => time(),
        ];
    }

    // ── Origen ────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    protected function geo(string $ip): ?array
    {
        // 1) ASN y titular de la red: RIPEstat (fiable y sin clave; es lo que mas importa para decidir).
        [$asn, $org] = $this->ripeAsn($ip);

        // 2) Pais y ciudad: el primer servicio que responda. Uno que falle (limite de uso,
        //    caido, lento) se salta 5 minutos para no gastar tiempo ni cuota con todas las IPs.
        $g = null;

        foreach (['ipwho', 'freeipapi', 'geojs', 'ripegeo', 'ipapi'] as $provider) {
            if (Cache::has($this->coolKey($provider))) {
                continue;
            }

            try {
                $g = $this->{'geo' . ucfirst($provider)}($ip);
            } catch (\Throwable $e) {
                Cache::put($this->coolKey($provider), 1, 300);
                $g = null;
            }

            if ($g !== null) {
                break;
            }
        }

        $asn = $asn ?? ($g['asn'] ?? null);
        $org = $org ?? ($g['org'] ?? null);

        if ($g === null && $asn === null && $org === null) {
            return null;
        }

        return $this->shape(
            (string) ($g['country'] ?? ''), (string) ($g['cc'] ?? ''), (string) ($g['region'] ?? ''), (string) ($g['city'] ?? ''),
            $asn, (string) ($org ?? '')
        );
    }

    protected function coolKey(string $provider): string
    {
        return 'antiddos:intel:cool:' . $provider;
    }

    /** Lanza si el servicio no ha podido atender la peticion (limite, error del servidor, bloqueo). */
    protected function guard($response): void
    {
        if ($response->status() === 429 || $response->status() === 403 || $response->serverError()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }
    }

    /** @return array{0: string|null, 1: string|null} [ASN, titular] */
    protected function ripeAsn(string $ip): array
    {
        if (Cache::has($this->coolKey('ripe'))) {
            return [null, null];
        }

        try {
            $r = Http::timeout(4)->acceptJson()->get('https://stat.ripe.net/data/prefix-overview/data.json', ['resource' => $ip, 'sourceapp' => 'antiddos-pterodactyl']);
            $this->guard($r);
            $a = $r->json('data.asns.0');

            if (is_array($a) && isset($a['asn'])) {
                $holder = trim((string) ($a['holder'] ?? ''));
                // RIPEstat a veces antepone «AS6057 - » al nombre: ya se muestra el ASN aparte.
                $holder = trim((string) preg_replace('/^AS\d+\s*-\s*/i', '', $holder));

                return ['AS' . $a['asn'], $holder !== '' ? $holder : null];
            }
        } catch (\Throwable $e) {
            Cache::put($this->coolKey('ripe'), 1, 120);
        }

        return [null, null];
    }

    /** @return array<string, mixed>|null */
    protected function geoIpwho(string $ip): ?array
    {
        $r = Http::timeout(4)->acceptJson()->get('https://ipwho.is/' . $ip, ['fields' => 'success,message,country,country_code,region,city,connection']);
        $this->guard($r);
        $j = $r->json();

        if (!is_array($j)) {
            throw new \RuntimeException('respuesta no valida');
        }

        if (!($j['success'] ?? false)) {
            if (preg_match('/limit|quota|rate/i', (string) ($j['message'] ?? ''))) {
                throw new \RuntimeException('limite de uso');
            }

            return null;
        }

        $c = (array) ($j['connection'] ?? []);

        return ['country' => $j['country'] ?? null, 'cc' => $j['country_code'] ?? null, 'region' => $j['region'] ?? null, 'city' => $j['city'] ?? null,
            'asn' => isset($c['asn']) ? 'AS' . $c['asn'] : null, 'org' => $c['org'] ?? $c['isp'] ?? null];
    }

    /** @return array<string, mixed>|null */
    protected function geoFreeipapi(string $ip): ?array
    {
        $r = Http::timeout(4)->acceptJson()->get('https://free.freeipapi.com/api/json/' . $ip);
        $this->guard($r);
        $j = $r->json();

        if (!is_array($j) || empty($j['countryCode'])) {
            return null;
        }

        $asn = isset($j['asn']) && $j['asn'] !== '' ? 'AS' . ltrim((string) $j['asn'], 'ASas') : null;

        return ['country' => $j['countryName'] ?? null, 'cc' => $j['countryCode'], 'region' => $j['regionName'] ?? null, 'city' => $j['cityName'] ?? null,
            'asn' => $asn, 'org' => $j['asnOrganization'] ?? null];
    }

    /** @return array<string, mixed>|null */
    protected function geoGeojs(string $ip): ?array
    {
        $r = Http::timeout(4)->acceptJson()->get("https://get.geojs.io/v1/ip/geo/{$ip}.json");
        $this->guard($r);
        $j = $r->json();

        if (!is_array($j) || empty($j['country_code'])) {
            return null;
        }

        return ['country' => $j['country'] ?? null, 'cc' => $j['country_code'], 'region' => $j['region'] ?? null, 'city' => $j['city'] ?? null,
            'asn' => isset($j['asn']) ? 'AS' . $j['asn'] : null, 'org' => $j['organization_name'] ?? null];
    }

    /** Geolocalizacion de RIPEstat (MaxMind GeoLite): solo pais y ciudad. @return array<string, mixed>|null */
    protected function geoRipegeo(string $ip): ?array
    {
        $r = Http::timeout(4)->acceptJson()->get('https://stat.ripe.net/data/geoloc/data.json', ['resource' => $ip, 'sourceapp' => 'antiddos-pterodactyl']);
        $this->guard($r);
        $loc = $r->json('data.located_resources.0.locations.0');

        if (!is_array($loc) || empty($loc['country'])) {
            return null;
        }

        return ['country' => $this->countryName((string) $loc['country']), 'cc' => $loc['country'], 'region' => null, 'city' => $loc['city'] ?? null];
    }

    /** @return array<string, mixed>|null */
    protected function geoIpapi(string $ip): ?array
    {
        $r = Http::timeout(4)->acceptJson()->get("https://ipapi.co/{$ip}/json/");
        $this->guard($r);
        $j = $r->json();

        if (!is_array($j)) {
            throw new \RuntimeException('respuesta no valida');
        }

        if (!empty($j['error'])) {
            if (preg_match('/rate|limit|quota/i', (string) ($j['reason'] ?? ''))) {
                throw new \RuntimeException('limite de uso');
            }

            return null;
        }

        return ['country' => $j['country_name'] ?? null, 'cc' => $j['country_code'] ?? null, 'region' => $j['region'] ?? null, 'city' => $j['city'] ?? null,
            'asn' => $j['asn'] ?? null, 'org' => $j['org'] ?? null];
    }

    /** Nombre del pais en español a partir del codigo (si PHP tiene intl; si no, el codigo). */
    protected function countryName(string $cc): string
    {
        $cc = strtoupper($cc);

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $cc, 'es');

            if (is_string($name) && $name !== '' && $name !== $cc) {
                return $name;
            }
        }

        return $cc;
    }

    /**
     * @return array<string, mixed>
     */
    protected function shape(string $country, string $cc, string $region, string $city, ?string $asn, string $org): array
    {
        return [
            'country' => $country ?: null,
            'cc' => strtoupper($cc) ?: null,
            'flag' => $this->flag($cc),
            'region' => $region ?: null,
            'city' => $city ?: null,
            'asn' => $asn,
            'org' => $org ?: null,
            'type' => $this->classify($org),
        ];
    }

    /** Emoji de bandera a partir del codigo de pais (dos indicadores regionales). */
    protected function flag(string $cc): ?string
    {
        $cc = strtoupper($cc);

        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            return null;
        }

        return mb_chr(127397 + ord($cc[0]), 'UTF-8') . mb_chr(127397 + ord($cc[1]), 'UTF-8');
    }

    /** Heuristica por el nombre del proveedor: residential | hosting | vpn. */
    protected function classify(string $org): string
    {
        if ($org === '') {
            return 'unknown';
        }

        if (preg_match('/\b(vpn|proxy|tor\b|anonym|mullvad|nordvpn|expressvpn|proton|private internet access|surfshark|cyberghost)/i', $org)) {
            return 'vpn';
        }

        if (preg_match('/(hosting|datacenter|data center|cloud|vps|server|colo(?:cation)?\b|amazon|aws\b|google|microsoft|azure|digitalocean|linode|akamai|vultr|ovh|hetzner|contabo|choopa|leaseweb|oracle|alibaba|tencent|scaleway|upcloud|m247|quadranet|psychz|sharktech|frantech|buyvm|ramnode|hostinger|ionos|netcup|datacamp|cdn77|zenlayer|serverius|hostwinds|racknerd|colocrossing|online s\.a\.s|ikoula)/i', $org)) {
            return 'hosting';
        }

        return 'residential';
    }

    // ── AbuseIPDB (opcional) ──────────────────────────────────────────────────

    protected function abuse(string $ip): ?int
    {
        $key = $this->abuseKey();

        if ($key === null) {
            return null;
        }

        return Cache::remember($this->key($ip, 'abuse'), 86400, function () use ($ip, $key) {
            try {
                $r = Http::timeout(8)->acceptJson()->withHeaders(['Key' => $key])
                    ->get('https://api.abuseipdb.com/api/v2/check', ['ipAddress' => $ip, 'maxAgeInDays' => 90]);

                return $r->successful() ? (int) ($r->json('data.abuseConfidenceScore') ?? 0) : null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    public function abuseKey(): ?string
    {
        $enc = (string) ShieldSetting::read('abuseipdb_key_enc', '');

        if ($enc === '') {
            return null;
        }

        try {
            return Crypt::decryptString($enc);
        } catch (DecryptException $e) {
            return null;
        }
    }

    public function setAbuseKey(?string $key): void
    {
        ShieldSetting::write(['abuseipdb_key_enc' => ($key === null || $key === '') ? '' : Crypt::encryptString($key)]);
    }

    protected function key(string $ip, string $part = 'v1'): string
    {
        return "antiddos:intel:{$part}:" . md5($ip);
    }
}
