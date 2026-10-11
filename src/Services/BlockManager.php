<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

class BlockManager
{
    public const CACHE_KEY = 'antiddos:blocklist';

    protected const IP_KEY = 'antiddos:blk:ip:';
    protected const READY_KEY = 'antiddos:blk:ready';

    protected const MAP_TTL = 30;

    public function map(): array
    {
        return Cache::remember(self::CACHE_KEY, self::MAP_TTL, function () {
            try {
                return ShieldBlock::query()
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
                    })
                    ->get(['ip', 'expires_at'])
                    ->mapWithKeys(fn (ShieldBlock $b) => [$b->ip => $b->expires_at ? $b->expires_at->getTimestamp() : 0])
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    public function isBlocked(string $ip): bool
    {
        $map = $this->map();

        if (!array_key_exists($ip, $map)) {
            return false;
        }

        return $map[$ip] === 0 || $map[$ip] > time();
    }

    public function remaining(string $ip): ?int
    {
        $key = self::IP_KEY . $ip;
        $values = Cache::many([self::READY_KEY, $key]);

        if (empty($values[self::READY_KEY])) {
            $this->index();
            $exp = Cache::get($key);
        } else {
            $exp = $values[$key] ?? null;
        }

        if ($exp === null || $exp === false) {
            return null;
        }

        $exp = (int) $exp;

        if ($exp !== 0 && $exp <= time()) {
            return null;
        }

        return $exp === 0 ? 0 : $exp - time();
    }

    protected function index(): void
    {
        foreach ($this->map() as $ip => $exp) {
            $this->remember((string) $ip, (int) $exp);
        }

        Cache::put(self::READY_KEY, 1, 86400);
    }

    protected function remember(string $ip, int $exp): void
    {
        if ($exp === 0) {
            Cache::forever(self::IP_KEY . $ip, 0);
        } elseif ($exp > time()) {
            Cache::put(self::IP_KEY . $ip, $exp, $exp - time() + 5);
        }
    }

    public function block(string $ip, ?int $minutes, ?string $reason = null, string $source = 'manual', ?int $userId = null, ?string $requestIp = null): ShieldBlock
    {
        if (($why = app(SafeIps::class)->protectedReason($ip, $requestIp)) !== null) {
            throw new \DomainException($why);
        }

        $existing = ShieldBlock::query()->where('ip', $ip)->first();

        $block = $existing ?? new ShieldBlock(['ip' => $ip]);
        $block->reason = $reason ?? $block->reason;
        $block->source = $source;
        $block->user_id = $userId;
        $block->strikes = $existing ? $existing->strikes + 1 : 1;
        $block->expires_at = $minutes === null ? null : Carbon::now()->addMinutes(max(1, $minutes));
        $block->save();

        $this->flush();
        $this->remember($ip, $block->expires_at ? $block->expires_at->getTimestamp() : 0);

        ShieldEvent::record(
            in_array($source, ['auto', 'intel'], true) ? 'auto-ban' : 'ban',
            sprintf('IP %s bloqueada %s%s', $ip, $minutes === null ? 'permanentemente' : "{$minutes} min", $reason ? " ({$reason})" : ''),
            $ip
        );

        return $block;
    }

    public function blockAuto(string $ip, string $reason): ShieldBlock
    {
        $settings = ShieldSetting::snapshot();
        $base = max(1, (int) $settings['ban_minutes']);
        $max = max($base, (int) $settings['ban_max_minutes']);

        $previous = (int) (ShieldBlock::query()->where('ip', $ip)->value('strikes') ?? 0);
        $minutes = (int) min($max, $base * (2 ** min($previous, 10)));

        return $this->block($ip, $minutes, $reason, 'auto');
    }

    public function unblock(string $ip): bool
    {
        $deleted = ShieldBlock::query()->where('ip', $ip)->delete() > 0;

        $this->flush();
        Cache::forget(self::IP_KEY . $ip);

        if ($deleted) {
            ShieldEvent::record('unban', "IP {$ip} desbloqueada", $ip);
        }

        return $deleted;
    }

    public function active(): Collection
    {
        return ShieldBlock::query()
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->orderByDesc('updated_at')
            ->get();
    }

    public function prune(): int
    {
        $removed = ShieldBlock::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now()->subDay())
            ->delete();

        $days = max(1, (int) ShieldSetting::read('retention_days', 14));
        ShieldEvent::query()->where('created_at', '<', Carbon::now()->subDays($days))->delete();

        try {
            ShieldMetric::query()->where('recorded_at', '<', Carbon::now()->subDays(min($days, MetricsService::KEEP_DAYS)))->delete();
        } catch (\Throwable $e) {
        }

        app(NetworkBlocks::class)->prune();

        $this->flush();

        return $removed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function feed(): string
    {
        $lines = [];

        foreach ($this->active() as $block) {
            $lines[] = 'B ' . $block->ip;
        }

        try {
            foreach (app(NetworkBlocks::class)->feedPrefixes() as $cidr) {
                $lines[] = 'A ' . $cidr;
            }
        } catch (\Throwable $e) {
        }

        $whitelist = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));

        foreach (array_unique(array_merge($whitelist, app(SafeIps::class)->infrastructure())) as $rule) {
            $lines[] = 'W ' . $rule;
        }

        $settings = ShieldSetting::snapshot();
        $map = [
            'fw_tcp_ports' => 'TCP_PORTS', 'fw_udp_ports' => 'UDP_PORTS', 'fw_conn_limit' => 'CONN_LIMIT',
            'fw_syn_rate' => 'SYN_RATE', 'fw_udp_rate' => 'UDP_RATE', 'fw_ban_seconds' => 'BAN_SECONDS',
            'fw_attack_mode' => 'ATTACK_MODE', 'fw_ban_mode' => 'BAN_MODE',
        ];

        $override = [];

        if ((string) ($settings['fw_tcp_ports'] ?? '') !== '' && !empty($settings['fw_auto'])) {
            $plan = app(MinecraftPorts::class)->plan();

            if ($plan['tcp'] !== '') {
                $override['fw_tcp_ports'] = $plan['tcp'];
            }

            if ($plan['udp'] !== '') {
                $override['fw_udp_ports'] = $plan['udp'];
            }
        }

        foreach ($map as $key => $agentKey) {
            $value = trim((string) ($override[$key] ?? $settings[$key] ?? ''));

            if ($agentKey === 'TCP_PORTS' && $value === '-') {
                continue;
            }

            if ($value !== '' && preg_match('/^([0-9:,-]+|monitor|balanced|strict)$/', $value)) {
                $lines[] = "C {$agentKey} {$value}";
            }
        }

        return implode("\n", $lines) . ($lines ? "\n" : '');
    }
}
