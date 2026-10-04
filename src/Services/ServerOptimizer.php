<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Str;
use Pterodactyl\Extensions\AntiDDoS\Models\ServerOptimization;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\Yaml\Yaml;

/**
 * Aplica y revierte perfiles de optimizacion en servidores de Minecraft.
 *
 * Que toca:
 *   1. El comando de arranque (servers.startup): banderas de la JVM de Aikar y
 *      un heap proporcional a la RAM del servidor.
 *   2. Los archivos de configuracion del servidor (server.properties,
 *      spigot.yml, bukkit.yml, paper-world-defaults.yml) a traves de Wings.
 *
 * Seguridad:
 *   - Antes de modificar un archivo se guarda una copia <archivo>.antiddos.bak
 *     en el propio servidor y se anota en la base de datos; 'revert' la restaura.
 *   - Solo se cambian claves que ya existen en el archivo.
 *   - El comando de arranque original se guarda en la base de datos.
 *   - Un error en un archivo no impide procesar el resto ni otros servidores.
 */
class ServerOptimizer
{
    /** Palabras en el nombre del egg/nest que identifican un servidor de Minecraft. */
    protected const MINECRAFT_HINTS = [
        'minecraft', 'paper', 'spigot', 'purpur', 'folia', 'bukkit', 'forge', 'fabric',
        'pufferfish', 'leaf', 'sponge', 'vanilla', 'bungee', 'waterfall', 'velocity',
    ];

    protected const BACKUP_SUFFIX = '.antiddos.bak';

    protected const MAX_FILE_BYTES = 8 * 1024 * 1024;

    public function isMinecraft(Server $server): bool
    {
        $haystack = Str::lower(trim(($server->egg->name ?? '') . ' ' . ($server->nest->name ?? '')));

        foreach (self::MINECRAFT_HINTS as $hint) {
            if (str_contains($haystack, $hint)) {
                return true;
            }
        }

        return (bool) preg_match('/^\S*java\S*\s/i', (string) $server->startup)
            && str_contains(Str::lower((string) $server->startup), '-jar');
    }

    /**
     * Aplica un perfil. Devuelve un informe: ['ok' => [...], 'warn' => [...]].
     *
     * @return array{ok: array<int, string>, warn: array<int, string>}
     */
    public function apply(Server $server, string $profileKey, bool $flags = true, bool $files = true, bool $restart = false, ?int $userId = null): array
    {
        $report = ['ok' => [], 'warn' => []];
        $profile = OptimizationProfiles::get($profileKey);

        if ($profile === null) {
            $report['warn'][] = "Perfil desconocido: {$profileKey}";

            return $report;
        }

        $state = ServerOptimization::query()->firstOrNew(['server_id' => $server->id]);
        $state->profile = $profileKey;
        $touchedFiles = $state->files ?? [];

        if ($flags) {
            $this->applyStartup($server, $state, $report);
        }

        if ($files) {
            $this->applyFiles($server, $profile, $touchedFiles, $report);
            $state->files = $touchedFiles;
        }

        $state->save();

        if ($flags) {
            $this->syncDaemon($server);
        }

        if ($restart) {
            $this->restartIfRunning($server, $report);
        }

        ShieldEvent::record(
            'optimize',
            sprintf('Perfil «%s» aplicado a %s (#%d)', $profile['label'], $server->name, $server->id),
            null,
            ['server_id' => $server->id, 'user_id' => $userId, 'profile' => $profileKey, 'ok' => $report['ok'], 'warn' => $report['warn']]
        );

        return $report;
    }

    /**
     * Revierte la optimizacion: restaura el comando de arranque y los archivos.
     *
     * @return array{ok: array<int, string>, warn: array<int, string>}
     */
    public function revert(Server $server, bool $restart = false, ?int $userId = null): array
    {
        $report = ['ok' => [], 'warn' => []];
        $state = ServerOptimization::query()->where('server_id', $server->id)->first();

        if ($state === null) {
            $report['warn'][] = 'Este servidor no tiene ninguna optimización aplicada por Anti-DDoS y Optimización.';

            return $report;
        }

        if ($state->original_startup !== null && $state->original_startup !== '') {
            $server->forceFill(['startup' => $state->original_startup])->save();
            $report['ok'][] = 'Comando de arranque original restaurado.';
            $this->syncDaemon($server);
        }

        foreach (($state->files ?? []) as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $backup = (string) ($entry['backup'] ?? '');

            if ($path === '' || $backup === '') {
                continue;
            }

            try {
                $repo = $this->files($server);
                $original = $repo->getContent($backup, self::MAX_FILE_BYTES);
                $repo->putContent($path, $original);
                $this->files($server)->deleteFiles('/', [ltrim($backup, '/')]);
                $report['ok'][] = "Restaurado {$path}.";
            } catch (\Throwable $e) {
                $report['warn'][] = "No se pudo restaurar {$path} (¿se borró la copia {$backup}?): " . $this->short($e);
            }
        }

        $state->delete();

        if ($restart) {
            $this->restartIfRunning($server, $report);
        }

        ShieldEvent::record(
            'optimize-revert',
            sprintf('Optimización revertida en %s (#%d)', $server->name, $server->id),
            null,
            ['server_id' => $server->id, 'user_id' => $userId]
        );

        return $report;
    }

    // ── Comando de arranque ───────────────────────────────────────────────────

    /**
     * @param array{ok: array<int, string>, warn: array<int, string>} $report
     */
    protected function applyStartup(Server $server, ServerOptimization $state, array &$report): void
    {
        if ((int) $server->memory <= 0) {
            $report['warn'][] = 'Banderas de JVM omitidas: el servidor tiene la RAM ilimitada (0), no se puede calcular el heap.';

            return;
        }

        $new = $this->buildStartup((string) $server->startup, (int) $server->memory);

        if ($new === null) {
            $report['warn'][] = 'Banderas de JVM omitidas: el comando de arranque no tiene el formato «java … -jar …».';

            return;
        }

        if ($new === $server->startup) {
            $report['ok'][] = 'El comando de arranque ya tenía las banderas optimizadas.';

            return;
        }

        if ($state->original_startup === null || $state->original_startup === '') {
            $state->original_startup = (string) $server->startup;
        }

        $server->forceFill(['startup' => $new])->save();
        $report['ok'][] = 'Banderas de JVM (Aikar) y heap proporcional aplicados al comando de arranque.';
    }

    /**
     * Reescribe «java <flags> -jar <resto>» con las banderas optimizadas y
     * conserva las propiedades de sistema (-D…) y modulos que ya hubiera.
     */
    public function buildStartup(string $startup, int $memoryMb): ?string
    {
        $startup = trim($startup);

        if (!preg_match('/^(?<java>\S*java\S*)\s+(?<flags>.*?)-jar\s+(?<rest>.+)$/is', $startup, $m)) {
            return null;
        }

        $keep = [];

        foreach (preg_split('/\s+/', trim($m['flags'])) ?: [] as $token) {
            if ($token === '' || preg_match('/^(-Xm[sx]|-Xss|-XX:|-Dusing\.aikars|-Daikars\.)/', $token)) {
                continue;
            }

            $keep[] = $token;
        }

        $percent = max(60, min(95, (int) ShieldSetting::read('heap_percent', 90)));
        $heap = '$(({{SERVER_MEMORY}}*' . $percent . '/100))M';

        $parts = array_merge(
            [$m['java'], "-Xms{$heap}", "-Xmx{$heap}", OptimizationProfiles::jvmFlags($memoryMb)],
            $keep,
            ['-jar', trim($m['rest'])]
        );

        return implode(' ', $parts);
    }

    // ── Archivos del servidor ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $profile
     * @param array<int, array{path: string, backup: string}> $touched
     * @param array{ok: array<int, string>, warn: array<int, string>} $report
     */
    protected function applyFiles(Server $server, array $profile, array &$touched, array &$report): void
    {
        foreach (OptimizationProfiles::fileMap() as $path => [$profileKey, $kind]) {
            $values = $profile[$profileKey] ?? [];

            if ($values === []) {
                continue;
            }

            if ($kind === 'yaml' && !class_exists(Yaml::class)) {
                $report['warn'][] = "{$path}: omitido (el panel no incluye symfony/yaml).";

                continue;
            }

            try {
                $content = $this->files($server)->getContent($path, self::MAX_FILE_BYTES);
            } catch (\Throwable $e) {
                $report['warn'][] = "{$path}: no existe todavía (se crea al arrancar el servidor por primera vez); vuelve a aplicar el perfil después.";

                continue;
            }

            $updated = $kind === 'properties'
                ? $this->editProperties($content, $values)
                : $this->editYaml($content, $values);

            if ($updated === null) {
                $report['ok'][] = "{$path}: ya estaba optimizado (o no tiene claves aplicables).";

                continue;
            }

            $backup = $path . self::BACKUP_SUFFIX;

            try {
                // La copia solo se hace la primera vez: si ya consta como tocado,
                // el archivo actual ya es el optimizado y pisaria el original.
                if (!$this->alreadyBackedUp($touched, $path)) {
                    $this->files($server)->putContent($backup, $content);
                    $touched[] = ['path' => $path, 'backup' => $backup];
                }

                $this->files($server)->putContent($path, $updated);
                $report['ok'][] = "{$path}: optimizado (copia en {$backup}).";
            } catch (\Throwable $e) {
                $report['warn'][] = "{$path}: no se pudo escribir: " . $this->short($e);
            }
        }
    }

    /**
     * @param array<int, array{path: string, backup: string}> $touched
     */
    protected function alreadyBackedUp(array $touched, string $path): bool
    {
        foreach ($touched as $entry) {
            if (($entry['path'] ?? null) === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cambia valores en un server.properties conservando comentarios, orden y
     * saltos de linea. Solo toca claves existentes. Devuelve null si no cambia nada.
     *
     * @param array<string, mixed> $values
     */
    public function editProperties(string $content, array $values): ?string
    {
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $changed = false;

        foreach ($lines as $i => $line) {
            if (!preg_match('/^\s*([A-Za-z0-9._-]+)\s*=(.*)$/', $line, $m)) {
                continue;
            }

            $key = $m[1];

            if (!array_key_exists($key, $values)) {
                continue;
            }

            $wanted = (string) $values[$key];

            if (trim($m[2]) !== $wanted) {
                $lines[$i] = "{$key}={$wanted}";
                $changed = true;
            }
        }

        return $changed ? implode($eol, $lines) : null;
    }

    /**
     * Cambia valores en un YAML (rutas con puntos). Solo toca claves existentes.
     * Devuelve null si no cambia nada o el YAML no se puede leer.
     *
     * Nota: al volver a serializar el YAML se pierden los comentarios del
     * archivo; por eso existe la copia .antiddos.bak.
     *
     * @param array<string, mixed> $paths
     */
    public function editYaml(string $content, array $paths): ?string
    {
        try {
            $data = Yaml::parse($content);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $changed = false;

        foreach ($paths as $path => $value) {
            if ($this->setIfExists($data, explode('.', (string) $path), $value)) {
                $changed = true;
            }
        }

        return $changed ? Yaml::dump($data, 16, 2) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    protected function setIfExists(array &$data, array $keys, mixed $value): bool
    {
        $key = array_shift($keys);

        if ($key === null || !array_key_exists($key, $data)) {
            return false;
        }

        if ($keys === []) {
            if ($data[$key] === $value || (is_numeric($data[$key]) && is_numeric($value) && (float) $data[$key] === (float) $value)) {
                return false;
            }

            $data[$key] = $value;

            return true;
        }

        if (!is_array($data[$key])) {
            return false;
        }

        return $this->setIfExists($data[$key], $keys, $value);
    }

    // ── Wings ─────────────────────────────────────────────────────────────────

    protected function files(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    protected function syncDaemon(Server $server): void
    {
        try {
            app(DaemonServerRepository::class)->setServer($server)->sync();
        } catch (\Throwable $e) {
            // Wings relee la configuracion al arrancar; la sincronizacion es un extra.
        }
    }

    /**
     * @param array{ok: array<int, string>, warn: array<int, string>} $report
     */
    protected function restartIfRunning(Server $server, array &$report): void
    {
        try {
            $details = app(DaemonServerRepository::class)->setServer($server)->getDetails();

            if (($details['state'] ?? null) !== 'running') {
                $report['ok'][] = 'El servidor no estaba encendido: no se reinició (los cambios se aplicarán al arrancar).';

                return;
            }

            app(DaemonPowerRepository::class)->setServer($server)->send('restart');
            $report['ok'][] = 'Servidor reiniciado.';
        } catch (\Throwable $e) {
            $report['warn'][] = 'No se pudo reiniciar el servidor: ' . $this->short($e);
        }
    }

    protected function short(\Throwable $e): string
    {
        return Str::limit($e->getMessage(), 160);
    }
}
