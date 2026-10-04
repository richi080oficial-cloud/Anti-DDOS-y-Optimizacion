<?php

namespace Pterodactyl\Extensions\AntiDDoS\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pterodactyl\Extensions\AntiDDoS\Models\ServerOptimization;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;
use Pterodactyl\Extensions\AntiDDoS\Services\OptimizationProfiles;
use Pterodactyl\Extensions\AntiDDoS\Services\ServerOptimizer;
use Pterodactyl\Models\Server;

class ShieldCommand extends Command
{
    protected $signature = 'antiddos:manage
                            {action=info : info | apikey | purge | prune | feed | ban | unban | bans | whitelist | optimize}
                            {args?* : Argumentos de la accion (IP, add|del|list, apply|revert|list...)}
                            {--key= : Clave concreta a usar con la accion apikey}
                            {--if-missing : Con apikey, no hace nada si ya existe una clave}
                            {--force : No pedir confirmacion (necesario para purge)}
                            {--minutes= : Duracion del bloqueo en minutos (ban); vacio = permanente}
                            {--reason= : Motivo del bloqueo (ban)}
                            {--profile= : Perfil de optimizacion (light|balanced|crowded)}
                            {--server= : ID numerico o uuidShort del servidor a optimizar/revertir}
                            {--all : Aplicar a todos los servidores de Minecraft}
                            {--no-flags : No tocar el comando de arranque}
                            {--no-files : No tocar los archivos de configuracion}
                            {--restart : Reiniciar los servidores encendidos}';

    protected $description = 'Utilidades internas de Anti-DDoS y Optimización (usadas por el CLI antiddos).';

    protected const TABLES = [
        'antiddos_optimizations',
        'antiddos_events',
        'antiddos_blocks',
        'antiddos_settings',
    ];

    public function handle(BlockManager $blocks, ServerOptimizer $optimizer): int
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
            'feed' => $this->feed($blocks),
            'ban' => $this->ban($blocks, $args),
            'unban' => $this->unban($blocks, $args),
            'bans' => $this->bans($blocks),
            'whitelist' => $this->whitelist($args),
            'optimize' => $this->optimize($optimizer, $args),
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
            ['Servidores optimizados', (string) ServerOptimization::query()->count()],
            ['Clave de API configurada', (string) (ShieldSetting::read('api_key_hash') ? 'si' : 'no')],
        ]);

        return self::SUCCESS;
    }

    protected function apiKey(): int
    {
        if ($this->option('if-missing') && (string) (ShieldSetting::read('api_key_hash') ?? '') !== '') {
            // La instalacion la llama en cada install/update para que la clave
            // se genere sola la primera vez; en las siguientes ya existe y no
            // hay que tocarla (invalidaria a los nodos ya configurados).
            return self::SUCCESS;
        }

        $key = (string) ($this->option('key') ?: Str::random(48));

        ShieldSetting::write(['api_key_hash' => password_hash($key, PASSWORD_DEFAULT)]);

        $this->newLine();
        $this->line('<info>Clave de API de Anti-DDoS y Optimización generada.</info>');
        $this->line('Guardala ahora, no se puede volver a mostrar:');
        $this->newLine();
        $this->line("  <comment>$key</comment>");
        $this->newLine();
        $this->line('Instala el agente en cada nodo con:');
        $this->line('  <comment>sudo antiddos node-install --panel-url=' . rtrim((string) config('app.url'), '/') . " --key=$key</comment>");

        return self::SUCCESS;
    }

    protected function purge(): int
    {
        if (!$this->option('force') && !$this->confirm('Se eliminaran TODAS las tablas de Anti-DDoS y Optimización. Las optimizaciones aplicadas a servidores NO se revierten. Continuar?', false)) {
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

    /** Lista de bloqueos y lista blanca en texto plano (la lee el agente de nodo local). */
    protected function feed(BlockManager $blocks): int
    {
        $this->getOutput()->write($blocks->feed());

        return self::SUCCESS;
    }

    /** @param array<int, string> $args */
    protected function ban(BlockManager $blocks, array $args): int
    {
        $ip = (string) ($args[0] ?? '');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Indica una IP valida: antiddos ban 203.0.113.7 [minutos]');

            return self::FAILURE;
        }

        $minutes = $this->option('minutes');
        $minutes = ($minutes === null || $minutes === '' || (int) $minutes === 0) ? null : (int) $minutes;

        $blocks->block($ip, $minutes, (string) ($this->option('reason') ?: 'Bloqueo manual (CLI)'), 'manual');
        $this->info("IP {$ip} bloqueada " . ($minutes === null ? 'permanentemente' : "{$minutes} minutos") . '.');

        return self::SUCCESS;
    }

    /** @param array<int, string> $args */
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

    /** @param array<int, string> $args */
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

    /** @param array<int, string> $args */
    protected function optimize(ServerOptimizer $optimizer, array $args): int
    {
        $sub = (string) ($args[0] ?? 'list');

        if ($sub === 'list') {
            $this->table(['Perfil', 'Nombre', 'Descripcion'], collect(OptimizationProfiles::all())
                ->map(fn (array $p, string $k) => [$k, $p['label'], Str::limit($p['description'], 80)])->values()->all());

            $servers = $this->minecraftServers();
            $states = ServerOptimization::query()->pluck('profile', 'server_id');

            $this->table(['ID', 'Nombre', 'RAM (MiB)', 'Nodo', 'Optimizado'], $servers->map(fn (Server $s) => [
                $s->id, Str::limit($s->name, 30), (string) $s->memory, $s->node->name ?? '-', $states[$s->id] ?? '-',
            ])->all());

            return self::SUCCESS;
        }

        if (!in_array($sub, ['apply', 'revert'], true)) {
            $this->error('Uso: antiddos optimize-server list | apply --profile=P (--server=ID|--all) | revert (--server=ID|--all)');

            return self::FAILURE;
        }

        $servers = $this->targetServers();

        if ($servers->isEmpty()) {
            $this->error('Indica --server=ID (o uuidShort) o --all. Usa "optimize-server list" para ver los servidores.');

            return self::FAILURE;
        }

        $profile = (string) ($this->option('profile') ?: ShieldSetting::read('default_profile', OptimizationProfiles::DEFAULT));

        if ($sub === 'apply' && OptimizationProfiles::get($profile) === null) {
            $this->error("Perfil desconocido: {$profile} (usa light, balanced o crowded)");

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($servers as $server) {
            $this->line("<info>{$server->name}</info> (#{$server->id})");

            try {
                $report = $sub === 'apply'
                    ? $optimizer->apply($server, $profile, !$this->option('no-flags'), !$this->option('no-files'), (bool) $this->option('restart'))
                    : $optimizer->revert($server, (bool) $this->option('restart'));
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  Error: ' . $e->getMessage());

                continue;
            }

            foreach ($report['ok'] as $msg) {
                $this->line("  <info>✓</info> {$msg}");
            }

            foreach ($report['warn'] as $msg) {
                $this->line("  <comment>!</comment> {$msg}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Server> */
    protected function minecraftServers()
    {
        /** @var ServerOptimizer $optimizer */
        $optimizer = app(ServerOptimizer::class);

        return Server::query()->with(['egg', 'nest', 'node'])->orderBy('name')->get()
            ->filter(fn (Server $s) => $optimizer->isMinecraft($s))
            ->values();
    }

    /** @return \Illuminate\Support\Collection<int, Server> */
    protected function targetServers()
    {
        if ($this->option('all')) {
            return $this->minecraftServers();
        }

        $id = (string) $this->option('server');

        if ($id === '') {
            return collect();
        }

        return Server::query()->with(['egg', 'nest', 'node'])
            ->where(fn ($q) => ctype_digit($id) ? $q->where('id', (int) $id) : $q->where('uuidShort', $id))
            ->get();
    }

    protected function unknown(string $action): int
    {
        $this->error("Accion desconocida: $action");

        return self::FAILURE;
    }
}
