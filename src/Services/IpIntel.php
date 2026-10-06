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
    protected const TTL_FAIL = 600;    // 10 min si la consulta falla
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

        Cache::put($this->key($ip), $intel, $geo === null && $matches === [] && $abuse === null ? self::TTL_FAIL : self::TTL);

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
            'status' => 'ok',
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
        try {
            $r = Http::timeout(6)->acceptJson()->get('https://ipwho.is/' . $ip, ['fields' => 'success,country,country_code,region,city,flag,connection']);
            $j = $r->json();

            if ($r->successful() && is_array($j) && ($j['success'] ?? false)) {
                $c = (array) ($j['connection'] ?? []);

                return $this->shape((string) ($j['country'] ?? ''), (string) ($j['country_code'] ?? ''), (string) ($j['region'] ?? ''), (string) ($j['city'] ?? ''),
                    isset($c['asn']) ? 'AS' . $c['asn'] : null, (string) ($c['org'] ?? $c['isp'] ?? ''));
            }
        } catch (\Throwable $e) {
            // Se prueba el respaldo.
        }

        try {
            $r = Http::timeout(6)->acceptJson()->get("https://ipapi.co/{$ip}/json/");
            $j = $r->json();

            if ($r->successful() && is_array($j) && empty($j['error'])) {
                return $this->shape((string) ($j['country_name'] ?? ''), (string) ($j['country_code'] ?? ''), (string) ($j['region'] ?? ''), (string) ($j['city'] ?? ''),
                    $j['asn'] ?? null, (string) ($j['org'] ?? ''));
            }
        } catch (\Throwable $e) {
            // Sin origen.
        }

        return null;
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
