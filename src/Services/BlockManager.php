<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

/**
 * Gestiona las IPs bloqueadas.
 *
 * La base de datos es la fuente de verdad (la ve el admin y la descargan los
 * nodos), pero en cada peticion solo se consulta un mapa pequeno en cache, asi
 * que el escudo no anade ninguna consulta SQL al camino normal.
 */
class BlockManager
{
    public const CACHE_KEY = 'antiddos:blocklist';

    /** Segundos que vive el mapa en cache (tambien es la demora maxima de un desbloqueo en otro worker). */
    protected const MAP_TTL = 30;

    /**
     * Mapa ip => caducidad (timestamp; 0 = permanente) de los bloqueos activos.
     *
     * @return array<string, int>
     */
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

    /** Segundos que le quedan al bloqueo (0 = permanente, null = no esta bloqueada). */
    public function remaining(string $ip): ?int
    {
        $map = $this->map();

        if (!array_key_exists($ip, $map)) {
            return null;
        }

        return $map[$ip] === 0 ? 0 : max(0, $map[$ip] - time());
    }

    /**
     * Bloquea una IP. $minutes = null -> permanente.
     */
    public function block(string $ip, ?int $minutes, ?string $reason = null, string $source = 'manual', ?int $userId = null): ShieldBlock
    {
        $existing = ShieldBlock::query()->where('ip', $ip)->first();

        $block = $existing ?? new ShieldBlock(['ip' => $ip]);
        $block->reason = $reason ?? $block->reason;
        $block->source = $source;
        $block->user_id = $userId;
        $block->strikes = $existing ? $existing->strikes + 1 : 1;
        $block->expires_at = $minutes === null ? null : Carbon::now()->addMinutes(max(1, $minutes));
        $block->save();

        $this->flush();

        ShieldEvent::record(
            $source === 'auto' ? 'auto-ban' : 'ban',
            sprintf('IP %s bloqueada %s%s', $ip, $minutes === null ? 'permanentemente' : "{$minutes} min", $reason ? " ({$reason})" : ''),
            $ip
        );

        return $block;
    }

    /**
     * Bloqueo automatico del escudo: la duracion se duplica con cada reincidencia
     * (dentro de la ventana en la que se recuerda a la IP) hasta un maximo.
     */
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

        if ($deleted) {
            ShieldEvent::record('unban', "IP {$ip} desbloqueada", $ip);
        }

        return $deleted;
    }

    /** @return Collection<int, ShieldBlock> */
    public function active(): Collection
    {
        return ShieldBlock::query()
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Borra bloqueos caducados hace mas de un dia (se conservan ese tiempo para
     * recordar reincidencias) y eventos antiguos. Devuelve cuantos bloqueos se quitaron.
     */
    public function prune(): int
    {
        $removed = ShieldBlock::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now()->subDay())
            ->delete();

        $days = max(1, (int) ShieldSetting::read('retention_days', 14));
        ShieldEvent::query()->where('created_at', '<', Carbon::now()->subDays($days))->delete();

        $this->flush();

        return $removed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Texto que descargan los nodos: "B <ip>" por cada bloqueo activo y
     * "W <ip/cidr>" por cada entrada de la lista blanca.
     */
    public function feed(): string
    {
        $lines = [];

        foreach ($this->active() as $block) {
            $lines[] = 'B ' . $block->ip;
        }

        foreach (IpMatcher::parseList((string) ShieldSetting::read('whitelist', '')) as $rule) {
            $lines[] = 'W ' . $rule;
        }

        return implode("\n", $lines) . ($lines ? "\n" : '');
    }
}
