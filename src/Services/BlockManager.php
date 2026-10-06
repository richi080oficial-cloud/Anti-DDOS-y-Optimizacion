<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;
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

    /** Una clave por IP bloqueada (valor: caducidad, 0 = permanente) y la marca de «indice creado». */
    protected const IP_KEY = 'antiddos:blk:ip:';
    protected const READY_KEY = 'antiddos:blk:ready';

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

    /**
     * Segundos que le quedan al bloqueo (0 = permanente, null = no esta bloqueada).
     *
     * Es lo que consulta el escudo web en CADA peticion, asi que no carga el mapa
     * entero (en un ataque puede tener miles de IPs): cada IP bloqueada tiene su
     * propia clave en cache y se piden las dos claves de una vez (MGET en Redis).
     */
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

    /** Crea las claves por IP a partir de la BD (tras reiniciar la cache o una vez al dia). */
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

    /**
     * Bloquea una IP. $minutes = null -> permanente.
     */
    public function block(string $ip, ?int $minutes, ?string $reason = null, string $source = 'manual', ?int $userId = null, ?string $requestIp = null): ShieldBlock
    {
        // Red de seguridad contra falsos positivos: ciertas IPs no se pueden bloquear nunca.
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
        Cache::forget(self::IP_KEY . $ip);

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

        try {
            ShieldMetric::query()->where('recorded_at', '<', Carbon::now()->subDays(min($days, 14)))->delete();
        } catch (\Throwable $e) {
            // Tabla de metricas aun sin migrar.
        }

        app(NetworkBlocks::class)->prune();

        $this->flush();

        return $removed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Texto que descargan los nodos: "B <ip>" por cada bloqueo activo, "A <cidr>"
     * por cada rango de un operador/pais bloqueado, "W <ip/cidr>" por cada entrada
     * de la lista blanca y "C CLAVE VALOR" por cada ajuste del firewall.
     */
    public function feed(): string
    {
        $lines = [];

        foreach ($this->active() as $block) {
            $lines[] = 'B ' . $block->ip;
        }

        // Operadores (ASN) y paises bloqueados: "A <cidr>" (set vs_asn de los nodos).
        try {
            foreach (app(NetworkBlocks::class)->feedPrefixes() as $cidr) {
                $lines[] = 'A ' . $cidr;
            }
        } catch (\Throwable $e) {
            // Tabla aun sin migrar: el resto del feed sigue funcionando.
        }

        $whitelist = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));

        // Los nodos, los agentes y el propio panel nunca se bloquean (aunque no esten en la lista blanca).
        foreach (array_unique(array_merge($whitelist, app(SafeIps::class)->infrastructure())) as $rule) {
            $lines[] = 'W ' . $rule;
        }

        // Ajustes del firewall gestionados desde el panel: "C CLAVE VALOR".
        $settings = ShieldSetting::snapshot();
        $map = [
            'fw_tcp_ports' => 'TCP_PORTS', 'fw_udp_ports' => 'UDP_PORTS', 'fw_conn_limit' => 'CONN_LIMIT',
            'fw_syn_rate' => 'SYN_RATE', 'fw_udp_rate' => 'UDP_RATE', 'fw_ban_seconds' => 'BAN_SECONDS',
            'fw_attack_mode' => 'ATTACK_MODE', 'fw_ban_mode' => 'BAN_MODE',
        ];

        // Con el firewall gestionado y la deteccion activa, los puertos que se envian
        // son los manuales mas los de los servidores de Minecraft detectados.
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

            // Un TCP_PORTS vacio ("-") dejaria al nodo sin proteccion: no se envia nunca.
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
