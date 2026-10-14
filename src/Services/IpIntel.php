<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

class IpIntel
{
    protected const TTL = 21600;
    protected const TTL_FAIL = 15;
    protected const MAX_PER_REQUEST = 5;

    protected static array $queued = [];

    public function __construct(protected ReputationLists $lists)
    {
    }

    public function enabled(): bool
    {
        return (bool) ShieldSetting::read('intel_enabled', true);
    }

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
                }
            });
        }

        if (count(self::$queued) < self::MAX_PER_REQUEST && Cache::add($this->key($ip, 'q'), 1, 90)) {
            self::$queued[] = $ip;
            app()->terminating(function () use ($ip) {
                try {
                    $this->lookup($ip);
                } catch (\Throwable $e) {
                }
            });
        }

        return ['status' => 'pending', 'verdict' => 'unknown', 'verdict_text' => 'Consultando…'];
    }

    public function lookup(string $ip, bool $force = false, bool $autoBlock = true): array
    {
        if (!$force && is_array($cached = Cache::get($this->key($ip)))) {
            return $cached;
        }

        if (IpMatcher::isInternal($ip)) {
            return ['status' => 'internal', 'verdict' => 'clean', 'verdict_text' => 'IP interna', 'reasons' => []];
        }

        try {
            $geo = $this->geo($ip);
            $matches = $this->lists->match($ip);
            $abuse = $this->abuse($ip);

            $intel = $this->assess($ip, $geo, $matches, $abuse);
        } finally {
            Cache::forget($this->key($ip, 'q'));
        }

        Cache::put($this->key($ip), $intel, $geo === null ? self::TTL_FAIL : self::TTL);

        if ($autoBlock && ($intel['verdict'] ?? '') === 'malicious') {
            try {
                app(AutoBlocker::class)->maybeBlock($ip, $intel);
            } catch (\Throwable $e) {
            }
        }

        return $intel;
    }

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

    public function cached(string $ip): ?array
    {
        $cached = Cache::get($this->key($ip));

        return is_array($cached) ? $cached : null;
    }

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

    protected function geo(string $ip): ?array
    {
        [$asn, $org] = $this->ripeAsn($ip);

        $g = null;
        $skipped = [];

        foreach (['ipwho', 'freeipapi', 'geojs', 'ripegeo', 'ipapi'] as $provider) {
            if (Cache::has($this->coolKey($provider))) {
                $skipped[] = $provider;

                continue;
            }

            $g = $this->tryGeo($provider, $ip);

            if ($g !== null) {
                break;
            }
        }

        if ($g === null) {
            foreach (array_slice($skipped, 0, 2) as $provider) {
                $g = $this->tryGeo($provider, $ip);

                if ($g !== null) {
                    break;
                }
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

    protected function tryGeo(string $provider, string $ip): ?array
    {
        try {
            $g = $this->{'geo' . ucfirst($provider)}($ip);
            Cache::forget($this->coolKey($provider));

            return $g;
        } catch (\Throwable $e) {
            $this->cool($provider, $e);

            return null;
        }
    }

    protected function cool(string $provider, \Throwable $e): void
    {
        $limited = (bool) preg_match('/HTTP (429|403)|limite/i', $e->getMessage());
        Cache::put($this->coolKey($provider), 1, $limited ? 90 : 20);
    }

    protected function coolKey(string $provider): string
    {
        return 'antiddos:intel:cool:' . $provider;
    }

    protected function guard($response): void
    {
        if ($response->status() === 429 || $response->status() === 403 || $response->serverError()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }
    }

    protected function ripeAsn(string $ip): array
    {
        if (Cache::has($this->coolKey('ripe'))) {
            return [null, null];
        }

        try {
            $r = Http::timeout(4)->retry(2, 300, null, false)->acceptJson()->get('https://stat.ripe.net/data/prefix-overview/data.json', ['resource' => $ip, 'sourceapp' => 'antiddos-pterodactyl']);
            $this->guard($r);
            $a = $r->json('data.asns.0');

            if (is_array($a) && isset($a['asn'])) {
                $holder = trim((string) ($a['holder'] ?? ''));
                $holder = trim((string) preg_replace('/^AS\d+\s*-\s*/i', '', $holder));

                return ['AS' . $a['asn'], $holder !== '' ? $holder : null];
            }
        } catch (\Throwable $e) {
            $this->cool('ripe', $e);
        }

        return [null, null];
    }

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

    protected function flag(string $cc): ?string
    {
        $cc = strtoupper($cc);

        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            return null;
        }

        return mb_chr(127397 + ord($cc[0]), 'UTF-8') . mb_chr(127397 + ord($cc[1]), 'UTF-8');
    }

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
