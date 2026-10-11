<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;

class MinecraftPorts
{
    public const CACHE_KEY = 'antiddos:mcports';

    public const MAX_WEIGHT = 15;

    protected const JAVA_HINTS = [
        'minecraft', 'paper', 'spigot', 'purpur', 'folia', 'bukkit', 'forge', 'fabric', 'pufferfish',
        'leaf', 'sponge', 'vanilla', 'bungee', 'waterfall', 'velocity', 'neoforge', 'quilt',
    ];

    protected const BEDROCK_HINTS = ['bedrock', 'pocketmine', 'nukkit', 'geyser', 'cloudburst', 'pmmp'];

    public function looksLikeMinecraft(?string $eggName, ?string $nestName = null): bool
    {
        $text = Str::lower(trim($eggName . ' ' . $nestName));

        foreach (array_merge(self::JAVA_HINTS, self::BEDROCK_HINTS) as $hint) {
            if (str_contains($text, $hint)) {
                return true;
            }
        }

        return false;
    }

    public function isBedrock(?string $eggName, ?string $nestName = null): bool
    {
        $text = Str::lower(trim($eggName . ' ' . $nestName));

        foreach (self::BEDROCK_HINTS as $hint) {
            if (str_contains($text, $hint)) {
                return true;
            }
        }

        return false;
    }

    public function eggs(): Collection
    {
        $chosen = $this->chosenEggIds();
        $counts = Server::query()->selectRaw('egg_id, COUNT(*) as total')->groupBy('egg_id')->pluck('total', 'egg_id');

        return Egg::query()->with('nest')->orderBy('name')->get()->map(function (Egg $egg) use ($chosen, $counts) {
            $nest = (string) ($egg->nest->name ?? '');
            $detected = $this->looksLikeMinecraft($egg->name, $nest);

            return [
                'id' => (int) $egg->id,
                'name' => (string) $egg->name,
                'nest' => $nest,
                'detected' => $detected,
                'selected' => $chosen === null ? $detected : in_array((int) $egg->id, $chosen, true),
                'bedrock' => $this->isBedrock($egg->name, $nest),
                'servers' => (int) ($counts[$egg->id] ?? 0),
            ];
        });
    }

    public function chosenEggIds(): ?array
    {
        $raw = trim((string) ShieldSetting::read('fw_egg_ids', ''));

        if ($raw === '') {
            return null;
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    public function plan(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, fn () => $this->compute());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected function compute(): array
    {
        $settings = ShieldSetting::snapshot();
        $manualTcp = (string) ($settings['fw_tcp_ports'] ?? '');
        $manualUdp = (string) ($settings['fw_udp_ports'] ?? '');
        $manualUdp = $manualUdp === '-' ? '' : $manualUdp;

        $autoTcp = [];
        $autoUdp = [];
        $details = [];
        $serverCount = 0;

        if (!empty($settings['fw_auto'])) {
            $extra = !empty($settings['fw_extra_ports']);
            $eggs = $this->eggs()->where('selected', true)->keyBy('id');

            if ($eggs->isNotEmpty()) {
                $servers = Server::query()->whereIn('egg_id', $eggs->keys()->all())->get(['id', 'name', 'egg_id', 'allocation_id']);
                $allocations = Allocation::query()->whereIn('server_id', $servers->pluck('id'))->get(['id', 'server_id', 'port'])->groupBy('server_id');

                foreach ($servers as $server) {
                    $bedrock = (bool) ($eggs[$server->egg_id]['bedrock'] ?? false);
                    $mine = [];

                    foreach ($allocations->get($server->id, collect()) as $allocation) {
                        $port = (int) $allocation->port;
                        $primary = (int) $allocation->id === (int) $server->allocation_id;

                        if ($primary) {
                            if ($bedrock) {
                                $autoUdp[] = $port;
                            } else {
                                $autoTcp[] = $port;
                            }

                            $mine[] = $port;
                        } elseif ($extra) {
                            $autoTcp[] = $port;
                            $autoUdp[] = $port;
                            $mine[] = $port;
                        }
                    }

                    if ($mine !== []) {
                        $serverCount++;
                    }
                }
            }
        }

        $tcp = $this->compact(array_merge($this->parse($manualTcp), array_map(fn (int $p) => [$p, $p], $autoTcp)));
        $udp = $this->compact(array_merge($this->parse($manualUdp), array_map(fn (int $p) => [$p, $p], $autoUdp)));

        return [
            'tcp' => $tcp,
            'udp' => $udp,
            'auto_tcp' => $this->compact(array_map(fn (int $p) => [$p, $p], $autoTcp)),
            'auto_udp' => $this->compact(array_map(fn (int $p) => [$p, $p], $autoUdp)),
            'servers' => $serverCount,
            'details' => $details,
        ];
    }

    public function parse(string $list): array
    {
        $out = [];

        foreach (explode(',', preg_replace('/\s+/', '', $list) ?? '') as $part) {
            if (preg_match('/^(\d{1,5})(?::(\d{1,5}))?$/', $part, $m)) {
                $from = (int) $m[1];
                $to = isset($m[2]) ? (int) $m[2] : $from;

                if ($from >= 1 && $to <= 65535 && $to >= $from) {
                    $out[] = [$from, $to];
                }
            }
        }

        return $out;
    }

    public function compact(array $ranges): string
    {
        if ($ranges === []) {
            return '';
        }

        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($ranges as [$from, $to]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $from <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        $weight = fn (array $r): int => array_sum(array_map(fn (array $x) => $x[0] === $x[1] ? 1 : 2, $r));

        while ($weight($merged) > self::MAX_WEIGHT && count($merged) > 1) {
            $best = 0;
            $gap = PHP_INT_MAX;

            for ($i = 0; $i < count($merged) - 1; $i++) {
                $g = $merged[$i + 1][0] - $merged[$i][1];

                if ($g < $gap) {
                    $gap = $g;
                    $best = $i;
                }
            }

            $merged[$best][1] = $merged[$best + 1][1];
            array_splice($merged, $best + 1, 1);
        }

        return implode(',', array_map(fn (array $r) => $r[0] === $r[1] ? (string) $r[0] : "{$r[0]}:{$r[1]}", $merged));
    }
}
