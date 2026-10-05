<?php

namespace Pterodactyl\Extensions\AntiDDoS\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldBlock;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Extensions\AntiDDoS\Services\AgentRegistry;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\IpMatcher;

class ShieldCommand extends Command
{
    protected $signature = 'antiddos:manage
                            {action=info : info | apikey | purge | prune | feed | ban | unban | bans | whitelist}
                            {args?* : Argumentos de la accion (IP, add|del|list)}
                            {--key= : Clave concreta a usar con la accion apikey}
                            {--if-missing : Con apikey, no hace nada si ya existe una clave}
                            {--force : No pedir confirmacion (necesario para purge)}
                            {--minutes= : Duracion del bloqueo en minutos (ban); vacio = permanente}
                            {--reason= : Motivo del bloqueo (ban)}
                            {--payload= : JSON del latido (lo envia el agente de nodo local)}';

    protected $description = 'Utilidades internas de Anti-DDoS y Optimización (usadas por el CLI antiddos).';

    protected const TABLES = [
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
            'feed' => $this->feed($blocks),
            'heartbeat' => $this->heartbeat(),
            'ban' => $this->ban($blocks, $args),
            'unban' => $this->unban($blocks, $args),
            'bans' => $this->bans($blocks),
            'whitelist' => $this->whitelist($args),
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

    /** Latido del agente de nodo local (mismo efecto que POST /api/antiddos/heartbeat). */
    protected function heartbeat(): int
    {
        $data = json_decode((string) $this->option('payload'), true);

        if (!is_array($data) || app(AgentRegistry::class)->record($data) === null) {
            $this->error('Payload no valido.');

            return self::FAILURE;
        }

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

    protected function unknown(string $action): int
    {
        $this->error("Accion desconocida: $action");

        return self::FAILURE;
    }
}
