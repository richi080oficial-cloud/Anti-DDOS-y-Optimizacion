<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

/**
 * Bloqueo automatico de IPs con veredicto «maliciosa».
 *
 * Es opcional (apagado por defecto) y se activa en Proteccion → Origen y
 * reputacion de IPs. Cuando esta activo:
 *   - Cada IP externa con conexiones hacia los puertos protegidos se valora
 *     (cada minuto, en segundo plano) y, si el veredicto es «maliciosa», se
 *     bloquea en el panel y en todos los nodos.
 *   - Tambien se bloquea al instante cuando una consulta de reputacion recien
 *     hecha (p. ej. al mirar la pestana Trafico) da «maliciosa».
 *   - La duracion es la elegida: 24 horas, 7 dias o para siempre.
 *
 * «Maliciosa» significa que la IP aparece en listas publicas de atacantes
 * (Blocklist.de, Spamhaus DROP, FireHOL Level 1, CINS Army) o que AbuseIPDB le da
 * un indice de abuso >= 75 %. Las sospechosas (Tor, hosting/VPN) NO se bloquean solas.
 *
 * Red de seguridad: SafeIps impide bloquear nodos, agentes, el panel, Cloudflare,
 * la lista blanca y las IPs privadas, aunque salgan en una lista.
 */
class AutoBlocker
{
    /** Consultas nuevas (con red) que se hacen como maximo en cada pasada. */
    public const MAX_NEW_LOOKUPS = 6;

    /** Segundos maximos de cada pasada (el cron de Pterodactyl lanza una cada minuto). */
    public const TIME_BUDGET = 40;

    public function __construct(
        protected IpIntel $intel,
        protected BlockManager $blocks,
        protected SafeIps $safe,
    ) {
    }

    /** Activo solo si estan activadas la consulta de reputacion y el bloqueo automatico. */
    public function enabled(): bool
    {
        return (bool) ShieldSetting::read('intel_enabled', true) && (bool) ShieldSetting::read('auto_block_malicious', false);
    }

    /** Minutos de bloqueo; null = para siempre. */
    public function minutes(): ?int
    {
        return match ((string) ShieldSetting::read('auto_block_duration', '24h')) {
            'forever' => null,
            '7d' => 7 * 1440,
            default => 1440,
        };
    }

    public function durationLabel(): string
    {
        return match ((string) ShieldSetting::read('auto_block_duration', '24h')) {
            'forever' => 'para siempre',
            '7d' => '7 días',
            default => '24 horas',
        };
    }

    /**
     * Bloquea la IP si el veredicto es «maliciosa» y no esta ya bloqueada.
     *
     * @param array<string, mixed> $intel
     */
    public function maybeBlock(string $ip, array $intel): ?ShieldBlock
    {
        if (!$this->enabled() || ($intel['verdict'] ?? '') !== 'malicious') {
            return null;
        }

        if ($this->blocks->remaining($ip) !== null) {
            return null;
        }

        $parts = [];

        if (!empty($intel['lists'])) {
            $parts[] = implode(', ', (array) $intel['lists']);
        }

        if (isset($intel['abuse']) && $intel['abuse'] !== null && (int) $intel['abuse'] >= 75) {
            $parts[] = 'AbuseIPDB ' . (int) $intel['abuse'] . ' %';
        }

        $reason = mb_substr('Reputación maliciosa (bloqueo automático): ' . implode(' · ', $parts), 0, 240);

        try {
            return $this->blocks->block($ip, $this->minutes(), $reason, 'intel');
        } catch (\DomainException $e) {
            // IP protegida (nodo, Cloudflare, lista blanca...): nunca se bloquea.
            return null;
        }
    }

    /**
     * Una pasada completa: valora las IPs conectadas ahora y bloquea las maliciosas.
     *
     * @return array{checked: int, lookups: int, blocked: array<int, string>}
     */
    public function run(): array
    {
        $result = ['checked' => 0, 'lookups' => 0, 'blocked' => []];

        if (!$this->enabled()) {
            return $result;
        }

        $ips = [];

        foreach (ShieldAgent::query()->get(['payload', 'last_seen_at']) as $agent) {
            if (!$agent->isOnline()) {
                continue;
            }

            foreach ((array) ($agent->payload['top_ips'] ?? []) as $row) {
                if (isset($row['ip'])) {
                    $ips[(string) $row['ip']] = true;
                }
            }
        }

        $start = microtime(true);

        foreach (array_keys($ips) as $ip) {
            if (microtime(true) - $start > self::TIME_BUDGET) {
                break;
            }

            if ($this->blocks->remaining($ip) !== null || $this->safe->protectedReason($ip) !== null) {
                continue;
            }

            $intel = $this->intel->cached($ip);

            if ($intel === null) {
                if ($result['lookups'] >= self::MAX_NEW_LOOKUPS) {
                    continue;
                }

                $result['lookups']++;
                $intel = $this->intel->lookup($ip, false, false);
            }

            $result['checked']++;

            if ($this->maybeBlock($ip, $intel) !== null) {
                $result['blocked'][] = $ip;
            }
        }

        return $result;
    }
}
