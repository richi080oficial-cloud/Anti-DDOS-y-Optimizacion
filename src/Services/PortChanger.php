<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldEvent;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/**
 * Cambia el puerto principal de un servidor moviendo su asignacion (allocation).
 *
 * Funciona como lo hace el panel en «Build Configuration»: el servidor pasa a
 * apuntar a otra asignacion de la misma IP y nodo, la antigua queda libre y se
 * avisa a Wings. Si la asignacion nueva no existe, se crea. El cambio de puerto
 * solo surte efecto al reiniciar el servidor (Docker fija los puertos al crear
 * el contenedor), por eso hay opcion de reinicio.
 */
class PortChanger
{
    public function __construct(protected PortAdvisor $advisor)
    {
    }

    /**
     * Puerto recomendado y libre para el servidor: primero una asignacion libre
     * ya existente en el rango seguro; si no hay, uno aleatorio sin usar.
     */
    public function suggest(Server $server, array $exclude = []): ?int
    {
        $current = Allocation::query()->find($server->allocation_id);

        if ($current === null) {
            return null;
        }

        $reserved = $this->reserved($current->node_id);
        $ok = fn (int $p) => !in_array($p, $exclude, true) && !in_array($p, $reserved, true) && !isset(PortAdvisor::SERVICE_PORTS[$p]) && !isset(PortAdvisor::DEFAULT_PORTS[$p]);

        $free = Allocation::query()
            ->where('node_id', $current->node_id)->where('ip', $current->ip)->whereNull('server_id')
            ->whereBetween('port', [PortAdvisor::SAFE_MIN, PortAdvisor::SAFE_MAX])
            ->pluck('port')->map(fn ($p) => (int) $p)->filter($ok)->values()->all();

        if ($free !== []) {
            return $free[array_rand($free)];
        }

        $taken = Allocation::query()->where('node_id', $current->node_id)->where('ip', $current->ip)->pluck('port')->flip();

        for ($i = 0; $i < 300; $i++) {
            $p = random_int(PortAdvisor::SAFE_MIN, PortAdvisor::SAFE_MAX);

            if (!isset($taken[$p]) && $ok($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @return array{ok: array<int, string>, warn: array<int, string>}
     */
    public function change(Server $server, int $port, bool $restart = false, ?int $userId = null): array
    {
        $report = ['ok' => [], 'warn' => []];
        $old = Allocation::query()->find($server->allocation_id);

        if ($old === null) {
            $report['warn'][] = 'El servidor no tiene asignación principal.';

            return $report;
        }

        if ((int) $old->port === $port) {
            $report['ok'][] = "Ya usa el puerto {$port}.";

            return $report;
        }

        $error = $this->validate($port, $old);

        if ($error !== null) {
            $report['warn'][] = $error;

            return $report;
        }

        $existing = Allocation::query()
            ->where('node_id', $old->node_id)->where('ip', $old->ip)->where('port', $port)->first();

        if ($existing !== null && $existing->server_id !== null && (int) $existing->server_id !== (int) $server->id) {
            $report['warn'][] = "El puerto {$port} ya lo usa otro servidor en este nodo.";

            return $report;
        }

        DB::transaction(function () use ($server, $old, $port, $existing) {
            $new = $existing ?? (new Allocation())->forceFill([
                'node_id' => $old->node_id,
                'ip' => $old->ip,
                'ip_alias' => $old->ip_alias,
                'port' => $port,
            ]);
            $new->forceFill(['server_id' => $server->id])->save();

            $server->forceFill(['allocation_id' => $new->id])->save();

            // La antigua queda libre para reutilizarla.
            $old->forceFill(['server_id' => null])->save();
        });

        $report['ok'][] = "Puerto cambiado de {$old->port} a {$port}.";

        try {
            app(DaemonServerRepository::class)->setServer($server->refresh())->sync();
        } catch (\Throwable $e) {
            $report['warn'][] = 'No se pudo avisar a Wings ahora; se sincronizará al reiniciar el servidor.';
        }

        if ($restart) {
            $this->restartIfRunning($server, $report);
        } else {
            $report['ok'][] = 'El cambio surte efecto al reiniciar el servidor.';
        }

        ShieldEvent::record(
            'port',
            sprintf('Puerto de %s (#%d) cambiado de %d a %d', $server->name, $server->id, $old->port, $port),
            null,
            ['server_id' => $server->id, 'user_id' => $userId, 'from' => (int) $old->port, 'to' => $port]
        );

        return $report;
    }

    /** Devuelve un mensaje de error si el puerto no se puede usar. */
    public function validate(int $port, Allocation $current): ?string
    {
        if ($port < 1024 || $port > 65535) {
            return 'El puerto debe estar entre 1024 y 65535.';
        }

        if (isset(PortAdvisor::SERVICE_PORTS[$port]) || in_array($port, $this->reserved($current->node_id), true)) {
            return "El puerto {$port} lo usa un servicio del sistema o de Wings.";
        }

        return null;
    }

    /** @return array<int, int> */
    protected function reserved(int $nodeId): array
    {
        $node = Node::query()->find($nodeId, ['id', 'daemonListen', 'daemonSFTP']);

        return $node === null ? [] : [(int) $node->daemonListen, (int) $node->daemonSFTP];
    }

    /**
     * @param array{ok: array<int, string>, warn: array<int, string>} $report
     */
    protected function restartIfRunning(Server $server, array &$report): void
    {
        try {
            $details = app(DaemonServerRepository::class)->setServer($server)->getDetails();

            if (($details['state'] ?? null) !== 'running') {
                $report['ok'][] = 'El servidor no estaba encendido: el puerto nuevo se usará al arrancar.';

                return;
            }

            app(DaemonPowerRepository::class)->setServer($server)->send('restart');
            $report['ok'][] = 'Servidor reiniciado con el puerto nuevo.';
        } catch (\Throwable $e) {
            $report['warn'][] = 'No se pudo reiniciar el servidor: ' . mb_substr($e->getMessage(), 0, 160);
        }
    }
}
