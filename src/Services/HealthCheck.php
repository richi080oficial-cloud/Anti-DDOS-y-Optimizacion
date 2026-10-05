<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Models\Node;

/**
 * Comprueba si la instalacion esta completa y devuelve un informe listo para
 * pintar: comprobaciones del panel y estado del agente de cada nodo.
 *
 * Niveles: ok | warn (funciona, pero mejorable) | error (falta algo).
 */
class HealthCheck
{
    protected const TABLES = [
        'antiddos_settings', 'antiddos_blocks', 'antiddos_events', 'antiddos_optimizations', 'antiddos_agents',
    ];

    /**
     * @return array{
     *   level: string, title: string, text: string, errors: int, warnings: int,
     *   checks: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>,
     *   agents: Collection<int, ShieldAgent>, version: string
     * }
     */
    public function run(?string $requestIp = null): array
    {
        $version = $this->version();
        $checks = [];

        // ── Panel ────────────────────────────────────────────────────────────
        $missing = array_values(array_filter(self::TABLES, fn (string $t) => !Schema::hasTable($t)));
        $checks[] = $missing === []
            ? $this->item('db', 'Base de datos', 'ok', 'Todas las tablas están creadas.')
            : $this->item('db', 'Base de datos', 'error', 'Faltan tablas: ' . implode(', ', $missing) . '.', 'sudo antiddos reinstall');

        $settings = ShieldSetting::snapshot();
        $checks[] = $settings['enabled']
            ? $this->item('shield', 'Escudo web del panel', 'ok', 'Activo: limita las peticiones por IP y bloquea abusos.')
            : $this->item('shield', 'Escudo web del panel', 'warn', 'Está apagado: el panel no se protege de ataques HTTP.', 'Actívalo en la pestaña Protección.');

        $hasKey = (string) (ShieldSetting::read('api_key_hash') ?? '') !== '';
        $checks[] = $hasKey
            ? $this->item('key', 'Clave de la API', 'ok', 'Creada (la usan los nodos que están en otra máquina).')
            : $this->item('key', 'Clave de la API', 'warn', 'No hay clave. Solo hace falta si algún nodo está en otra máquina.', 'sudo antiddos apikey');

        $cache = (string) config('cache.default', 'file');
        $checks[] = match (true) {
            in_array($cache, ['array', 'null'], true) => $this->item('cache', 'Caché del panel', 'error', "El driver de caché es «{$cache}»: los contadores no se conservan entre peticiones y el escudo web no funciona.", 'Configura CACHE_DRIVER=redis en el .env del panel.'),
            $cache === 'file' => $this->item('cache', 'Caché del panel', 'warn', 'Usa archivos: funciona, pero Redis aguanta mucho mejor un ataque.'),
            default => $this->item('cache', 'Caché del panel', 'ok', "Driver «{$cache}»: correcto para contar peticiones."),
        };

        if ($requestIp !== null) {
            $checks[] = IpMatcher::isCloudflare($requestIp)
                ? $this->item('ip', 'IP real de los visitantes', 'error', "El panel ve una IP de Cloudflare ({$requestIp}) en vez de la IP real del visitante. Hasta que se configure, el escudo web NO actúa (por seguridad se desactiva solo para no bloquear a todos los usuarios de ese nodo de Cloudflare).", 'Añade en el .env del panel: TRUSTED_PROXIES con los rangos de https://www.cloudflare.com/ips-v4 y ejecuta: php artisan config:clear')
                : (IpMatcher::isInternal($requestIp)
                ? $this->item('ip', 'IP real de los visitantes', 'warn', "El panel ve tu IP como interna ({$requestIp}). Si entras desde internet, falta configurar TRUSTED_PROXIES (nginx/Cloudflare): hasta entonces el escudo web no actuará.", 'Revisa TRUSTED_PROXIES en el .env del panel.')
                : $this->item('ip', 'IP real de los visitantes', 'ok', "El panel ve tu IP pública ({$requestIp})."));
        }

        // ── Nodos ────────────────────────────────────────────────────────────
        $agents = ShieldAgent::query()->orderBy('host')->get();
        $nodes = $this->nodes($agents, $version);

        $errors = collect($checks)->where('level', 'error')->count() + collect($nodes)->where('level', 'error')->count();
        $warnings = collect($checks)->where('level', 'warn')->count() + collect($nodes)->where('level', 'warn')->count();

        [$level, $title, $text] = match (true) {
            $errors > 0 => ['error', 'Falta algo por instalar o configurar', "Hay {$errors} punto(s) pendientes. Revisa la lista de abajo: cada uno indica cómo arreglarlo."],
            $warnings > 0 => ['warn', 'Instalado, con avisos', "Todo funciona, pero hay {$warnings} aviso(s) que conviene revisar."],
            default => ['ok', 'Todo instalado y funcionando', 'El escudo del panel y el firewall de todos los nodos están activos.'],
        };

        return compact('level', 'title', 'text', 'errors', 'warnings', 'checks', 'nodes', 'agents', 'version');
    }

    public function version(): string
    {
        return trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: 'dev';
    }

    /**
     * Estado del agente de cada nodo de Pterodactyl.
     *
     * @param Collection<int, ShieldAgent> $agents
     * @return array<int, array<string, mixed>>
     */
    protected function nodes(Collection $agents, string $version): array
    {
        try {
            $nodes = Node::query()->orderBy('name')->get(['id', 'name', 'fqdn']);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($nodes as $node) {
            $agent = $this->matchAgent($node, $agents, $nodes->count());

            if ($agent === null) {
                $out[] = [
                    'name' => $node->name, 'fqdn' => $node->fqdn, 'agent' => null, 'level' => 'error',
                    'detail' => 'No hay ningún agente conectado: este nodo NO está protegido.',
                    'fix' => 'sudo antiddos node-install',
                ];

                continue;
            }

            $p = $agent->payload ?? [];
            $seen = $agent->secondsSinceSeen();

            if (!$agent->isOnline()) {
                $level = 'warn';
                $detail = 'Sin respuesta desde hace ' . $this->ago($seen) . '. ¿Está apagado el servicio?';
                $fix = 'sudo systemctl status antiddos-sync.timer antiddos-fw';
            } elseif (!($p['active'] ?? false)) {
                $level = 'error';
                $detail = 'El agente responde, pero las reglas del firewall NO están activas.';
                $fix = 'sudo antiddos fw apply';
            } elseif (($p['tcp'] ?? '') === '' && ($p['udp'] ?? '') === '') {
                $level = 'warn';
                $detail = 'Activo, pero no protege ningún puerto de Minecraft. Configúralos en la pestaña Protección.';
                $fix = null;
            } elseif ($agent->version !== $version && $agent->version !== 'desconocida') {
                $level = 'warn';
                $detail = "Activo, pero con versión {$agent->version} (la del panel es {$version}).";
                $fix = 'sudo antiddos update';
            } else {
                $level = 'ok';
                $detail = 'Protegido y respondiendo (hace ' . $this->ago($seen) . ').';
                $fix = null;
            }

            $out[] = [
                'name' => $node->name, 'fqdn' => $node->fqdn, 'agent' => $agent, 'level' => $level,
                'detail' => $detail, 'fix' => $fix,
            ];
        }

        return $out;
    }

    /**
     * Relaciona un nodo con su agente: por IP del FQDN, por nombre de host o,
     * si solo hay un nodo y un agente, directamente.
     *
     * @param Collection<int, ShieldAgent> $agents
     */
    protected function matchAgent(Node $node, Collection $agents, int $nodeCount): ?ShieldAgent
    {
        $fqdn = strtolower((string) $node->fqdn);
        $ips = $this->resolve($fqdn);

        foreach ($agents as $agent) {
            if (strtolower($agent->host) === $fqdn || array_intersect($ips, (array) $agent->ips) !== []) {
                return $agent;
            }
        }

        return ($nodeCount === 1 && $agents->count() === 1) ? $agents->first() : null;
    }

    /**
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return Cache::remember('antiddos:dns:' . md5($host), 600, function () use ($host) {
            $list = @gethostbynamel($host);

            return is_array($list) ? $list : [];
        });
    }

    /** @return array<string, mixed> */
    protected function item(string $key, string $label, string $level, string $detail, ?string $fix = null): array
    {
        return compact('key', 'label', 'level', 'detail', 'fix');
    }

    protected function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => max(1, $seconds) . ' s',
            $seconds < 5400 => round($seconds / 60) . ' min',
            $seconds < 129600 => round($seconds / 3600) . ' h',
            default => round($seconds / 86400) . ' días',
        };
    }
}
