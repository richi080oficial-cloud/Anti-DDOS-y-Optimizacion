<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

class AutoBlocker
{
    public const MAX_NEW_LOOKUPS = 6;

    public const TIME_BUDGET = 40;

    public function __construct(
        protected IpIntel $intel,
        protected BlockManager $blocks,
        protected SafeIps $safe,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) ShieldSetting::read('intel_enabled', true) && (bool) ShieldSetting::read('auto_block_malicious', false);
    }

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
            return null;
        }
    }

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
