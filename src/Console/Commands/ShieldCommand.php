<?php

namespace Pterodactyl\Extensions\AntiDDoS\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldNetBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\AgentRegistry;
use Pterodactyl\Extensions\AntiDDoS\Services\AutoBlocker;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\IpIntel;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;
use Pterodactyl\Extensions\AntiDDoS\Services\MetricsService;
use Pterodactyl\Extensions\AntiDDoS\Services\NetworkBlocks;
use Pterodactyl\Extensions\AntiDDoS\Services\ReputationLists;
use Pterodactyl\Extensions\AntiDDoS\Services\SafeIps;

class ShieldCommand extends Command
{
    protected $signature = 'antiddos:manage
                            {action=info : info | ip | refresh-lists | auto-block | apikey | purge | prune | feed | ban | unban | bans | whitelist | asn | country | refresh-nets | compact-metrics | heartbeat | sync}
                            {args?* : Argumentos de la accion (IP, add|del|list, block|unblock|list AS199585)}
                            {--key= : Clave concreta a usar con la accion apikey}
                            {--if-missing : Con apikey, no hace nada si ya existe una clave}
                            {--force : No pedir confirmacion (purge) o bloquear una red muy grande (asn/country)}
                            {--minutes= : Duracion del bloqueo en minutos (ban); vacio = permanente}
                            {--hours= : Duracion del bloqueo en horas (asn/country); vacio = permanente}
                            {--reason= : Motivo del bloqueo (ban)}
                            {--payload= : JSON del latido (lo envia el agente de nodo local)}
                            {--host= : Nombre del host que pide el feed (lo envia el agente de nodo local)}';

    protected $description = 'Utilidades internas de Anti-DDoS y Optimización (usadas por el CLI antiddos).';

    protected const TABLES = [
        'antiddos_net_blocks',
        'antiddos_metrics',
        'antiddos_agents',
        'antiddos_events',
        'antiddos_blocks',
        'antiddos_settings',
    ];

    public function handle(BlockManager $blocks): int
    {
        $action = (string) $this->argument('action');
        $args = array_values((array) $this->argument('args'));

        if ($action !== 'purge' && !Schema::hasTable('antiddos_settings')) {
            $this->error('Las tablas de Anti-DDoS y Optimización no existen. Ejecuta: antiddos install');

            return self::FAILURE;
        }

        return match ($action) {
            'info' => $this->info_(),
            'apikey' => $this->apiKey(),
            'purge' => $this->purge(),
            'prune' => $this->prune($blocks),
            'ip' => $this->ip($args),
            'refresh-lists' => $this->refreshLists(),
            'auto-block' => $this->autoBlock(),
            'feed' => $this->feed($blocks),
            'heartbeat' => $this->heartbeat(),
            'sync' => $this->sync($blocks),
            'ban' => $this->ban($blocks, $args),
            'unban' => $this->unban($blocks, $args),
            'bans' => $this->bans($blocks),
            'whitelist' => $this->whitelist($args),
            'asn', 'operador' => $this->net('asn', $args),
            'country', 'pais' => $this->net('country', $args),
            'refresh-nets' => $this->refreshNets(),
            'compact-metrics' => $this->compactMetrics(),
            default => $this->unknown($action),
        };
    }

    protected function info_(): int
    {
        $settings = ShieldSetting::snapshot();

        $this->line('<info>Anti-DDoS y Optimización</info> - estado actual');
        $this->newLine();

        $rows = [];
        foreach ($settings as $key => $value) {
            $rows[] = [$key, is_bool($value) ? ($value ? 'si' : 'no') : Str::limit(str_replace("\n", ', ', (string) $value), 60)];
        }
        $this->table(['Ajuste', 'Valor'], $rows);

        $this->table(['Metrica', 'Total'], [
            ['IPs bloqueadas ahora', (string) (new BlockManager())->active()->count()],
            ['Eventos registrados', (string) ShieldEvent::query()->count()],
            ['Clave de API configurada', (string) (ShieldSetting::read('api_key_hash') ? 'si' : 'no')],
        ]);

        return self::SUCCESS;
    }

    protected function apiKey(): int
    {
        if ($this->option('if-missing') && (string) (ShieldSetting::read('api_key_hash') ?? '') !== '') {
            return self::SUCCESS;
        }

        $key = (string) ($this->option('key') ?: Str::random(48));

        ShieldSetting::write([
            'api_key_hash' => password_hash($key, PASSWORD_DEFAULT),
            'api_key_enc' => Crypt::encryptString($key),
        ]);

        $this->newLine();
        $this->line('<info>Clave de API de Anti-DDoS y Optimización generada.</info>');
        $this->line('También podrás verla luego en Admin → Anti-DDoS y Optimización:');
        $this->newLine();
        $this->line("  <comment>$key</comment>");
        $this->newLine();
        $this->line('Instala el agente en cada nodo con:');
        $this->line('  <comment>sudo antiddos node-install --panel-url=' . rtrim((string) config('app.url'), '/') . " --key=$key</comment>");

        return self::SUCCESS;
    }

    protected function purge(): int
    {
        if (!$this->option('force') && !$this->confirm('Se eliminaran TODAS las tablas de Anti-DDoS y Optimización. Continuar?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
                $this->line("Tabla eliminada: $table");
            }
        }

        ShieldSetting::flush();
        (new BlockManager())->flush();
        $this->info('Datos de Anti-DDoS y Optimización eliminados.');

        return self::SUCCESS;
    }

    protected function prune(BlockManager $blocks): int
    {
        $removed = $blocks->prune();
        ShieldSetting::flush();

        $this->info("Bloqueos caducados eliminados: $removed (y eventos antiguos).");

        return self::SUCCESS;
    }

    protected function ip(array $args): int
    {
        $ip = (string) ($args[0] ?? '');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Uso: antiddos ip 203.0.113.7');

            return self::FAILURE;
        }

        if (!app(ReputationLists::class)->anyLoaded()) {
            $this->line('<comment>Las listas de reputación no están descargadas; se descargan ahora…</comment>');
            $this->refreshLists();
        }

        $intel = app(IpIntel::class)->lookup($ip, true, false);
        $why = app(SafeIps::class)->protectedReason($ip);

        $this->table(['Campo', 'Valor'], [
            ['IP', $ip],
            ['Veredicto', ($intel['verdict_text'] ?? '—')],
            ['País', trim(($intel['flag'] ?? '') . ' ' . ($intel['country'] ?? '—'))],
            ['Ciudad / región', trim(($intel['city'] ?? '—') . ' · ' . ($intel['region'] ?? ''))],
            ['Proveedor (ASN)', trim(($intel['org'] ?? '—') . ' ' . ($intel['asn'] ?? ''))],
            ['Tipo de red', (string) ($intel['type'] ?? '—')],
            ['Listas de atacantes', ($intel['lists'] ?? []) ? implode(', ', $intel['lists']) : 'ninguna'],
            ['AbuseIPDB', isset($intel['abuse']) && $intel['abuse'] !== null ? $intel['abuse'] . ' %' : 'no configurado'],
            ['¿Se puede bloquear?', $why ?? 'sí'],
        ]);

        foreach ((array) ($intel['reasons'] ?? []) as $reason) {
            $this->line(' · ' . $reason);
        }

        return self::SUCCESS;
    }

    protected function autoBlock(): int
    {
        $auto = app(AutoBlocker::class);

        if (!$auto->enabled()) {
            $this->line('El bloqueo automático de IPs maliciosas está desactivado (Protección → Origen y reputación de IPs).');

            return self::SUCCESS;
        }

        $r = $auto->run();
        $this->line(sprintf('IPs valoradas: %d · consultas nuevas: %d · bloqueadas: %d (%s)', $r['checked'], $r['lookups'], count($r['blocked']), $auto->durationLabel()));

        foreach ($r['blocked'] as $ip) {
            $this->line("  <comment>⛔</comment> {$ip}");
        }

        return self::SUCCESS;
    }

    protected function refreshLists(): int
    {
        $this->line('Descargando listas públicas de reputación…');

        foreach (app(ReputationLists::class)->refresh() as $id => $r) {
            $name = ReputationLists::SOURCES[$id][0];
            $this->line($r['ok'] ? "  <info>✓</info> {$name}: {$r['count']} entradas" : "  <comment>!</comment> {$name}: {$r['error']}");
        }

        return self::SUCCESS;
    }

    protected function heartbeat(): int
    {
        $data = json_decode((string) $this->option('payload'), true);

        if (!is_array($data) || app(AgentRegistry::class)->record($data) === null) {
            $this->error('Payload no valido.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function sync(BlockManager $blocks): int
    {
        $data = json_decode((string) $this->option('payload'), true);

        if (is_array($data)) {
            try {
                app(AgentRegistry::class)->record($data);
            } catch (\Throwable $e) {
            }
        }

        return $this->feed($blocks);
    }

    protected function feed(BlockManager $blocks): int
    {
        $this->getOutput()->write($blocks->feed((string) $this->option('host')));

        return self::SUCCESS;
    }

    protected function ban(BlockManager $blocks, array $args): int
    {
        $ip = (string) ($args[0] ?? '');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Indica una IP valida: antiddos ban 203.0.113.7 [minutos]');

            return self::FAILURE;
        }

        $minutes = $this->option('minutes');
        $minutes = ($minutes === null || $minutes === '' || (int) $minutes === 0) ? null : (int) $minutes;

        try {
            $blocks->block($ip, $minutes, (string) ($this->option('reason') ?: 'Bloqueo manual (CLI)'), 'manual');
        } catch (\DomainException $e) {
            $this->error('No se bloquea: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("IP {$ip} bloqueada " . ($minutes === null ? 'permanentemente' : "{$minutes} minutos") . '.');

        return self::SUCCESS;
    }

    protected function unban(BlockManager $blocks, array $args): int
    {
        $ip = (string) ($args[0] ?? '');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Indica una IP valida: antiddos unban 203.0.113.7');

            return self::FAILURE;
        }

        $this->line($blocks->unblock($ip) ? "IP {$ip} desbloqueada." : "{$ip} no estaba bloqueada en el panel.");

        return self::SUCCESS;
    }

    protected function bans(BlockManager $blocks): int
    {
        $rows = $blocks->active()->map(fn (ShieldBlock $b) => [
            $b->ip,
            $b->source,
            $b->expires_at ? $b->expires_at->diffForHumans() : 'permanente',
            (string) $b->strikes,
            Str::limit((string) $b->reason, 50),
        ])->all();

        if ($rows === []) {
            $this->line('No hay IPs bloqueadas en el panel.');

            return self::SUCCESS;
        }

        $this->table(['IP', 'Origen', 'Caduca', 'Reincidencias', 'Motivo'], $rows);

        return self::SUCCESS;
    }

    protected function whitelist(array $args): int
    {
        $sub = (string) ($args[0] ?? 'list');
        $entry = trim((string) ($args[1] ?? ''));
        $list = IpMatcher::parseList((string) ShieldSetting::read('whitelist', ''));

        if ($sub === 'list') {
            $this->line($list === [] ? 'La lista blanca del panel esta vacia.' : implode(PHP_EOL, $list));

            return self::SUCCESS;
        }

        if (!in_array($sub, ['add', 'del', 'remove'], true) || !IpMatcher::valid($entry)) {
            $this->error('Uso: antiddos whitelist add|del|list [IP|CIDR]');

            return self::FAILURE;
        }

        $list = $sub === 'add'
            ? array_values(array_unique([...$list, $entry]))
            : array_values(array_filter($list, fn (string $e) => $e !== $entry));

        ShieldSetting::write(['whitelist' => implode("\n", $list)]);
        $this->info($sub === 'add' ? "{$entry} anadida a la lista blanca." : "{$entry} quitada de la lista blanca.");

        return self::SUCCESS;
    }

    protected function net(string $kind, array $args): int
    {
        $nets = app(NetworkBlocks::class);
        $sub = strtolower((string) ($args[0] ?? 'list'));
        $value = trim(implode(' ', array_slice($args, 1)));
        $example = $kind === 'asn' ? 'AS199585' : 'PL';

        if (in_array($sub, ['list', 'ls'], true)) {
            $rows = $nets->active()->where('kind', $kind)->map(fn (ShieldNetBlock $b) => [
                $b->label(),
                (string) $b->prefix_count,
                number_format($b->ip_count, 0, ',', '.'),
                $b->expires_at ? $b->expires_at->diffForHumans() : 'permanente',
                Str::limit((string) $b->reason, 40),
            ])->values()->all();

            if ($rows === []) {
                $this->line($kind === 'asn' ? 'No hay operadores bloqueados.' : 'No hay países bloqueados.');
            } else {
                $this->table([$kind === 'asn' ? 'Operador' : 'País', 'Rangos', 'IPs', 'Caduca', 'Motivo'], $rows);
            }

            if (($expr = $nets->cloudflareExpression()) !== null) {
                $this->line('Regla para Cloudflare (WAF → Reglas personalizadas → Bloquear): <comment>' . $expr . '</comment>');
            }

            return self::SUCCESS;
        }

        if ($sub === 'refresh') {
            return $this->refreshNets();
        }

        if (!in_array($sub, ['block', 'add', 'ban', 'unblock', 'del', 'remove', 'unban'], true) || $value === '') {
            $this->error("Uso: antiddos {$kind} block|unblock|list {$example} [--hours=24] [--reason=\"motivo\"]");

            return self::FAILURE;
        }

        try {
            if (in_array($sub, ['unblock', 'del', 'remove', 'unban'], true)) {
                $this->line($nets->unblock($kind, $value) ? "{$value} desbloqueado. Los nodos lo aplican en menos de un minuto." : "{$value} no estaba bloqueado.");

                return self::SUCCESS;
            }

            $hours = $this->option('hours');
            $hours = ($hours === null || $hours === '' || (int) $hours === 0) ? null : (int) $hours;

            $this->line('Descargando los rangos de IP…');
            $block = $nets->block($kind, $value, $hours, (string) ($this->option('reason') ?: 'Bloqueo manual (CLI)'), null, null, (bool) $this->option('force'));
        } catch (\DomainException|\RuntimeException $e) {
            $this->error('No se bloquea: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s bloqueado %s: %d rango(s), %s IPs. Los nodos lo aplican en menos de un minuto y el panel ya le responde 403.',
            $block->label(), $hours === null ? 'permanentemente' : "{$hours} h", $block->prefix_count, number_format($block->ip_count, 0, ',', '.')
        ));
        $this->line('Rangos: ' . Str::limit(implode(', ', (array) $block->prefixes), 400));

        return self::SUCCESS;
    }

    protected function compactMetrics(): int
    {
        try {
            $n = app(MetricsService::class)->compact();
        } catch (\Throwable $e) {
            $this->error('No se pudo aclarar el historial: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line("Historial de la gráfica aclarado: {$n} muestras antiguas borradas.");

        return self::SUCCESS;
    }

    protected function refreshNets(): int
    {
        $results = app(NetworkBlocks::class)->refresh();

        if ($results === []) {
            $this->line('No hay operadores ni países bloqueados.');
        }

        foreach ($results as $label => $result) {
            $this->line("  {$label}: {$result}");
        }

        return self::SUCCESS;
    }

    protected function unknown(string $action): int
    {
        $this->error("Accion desconocida: $action");

        return self::FAILURE;
    }
}
