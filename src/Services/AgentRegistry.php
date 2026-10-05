<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Carbon;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldMetric;

/**
 * Registra los latidos de los agentes de nodo. El payload viene de un script
 * del servidor, pero se valida campo a campo: nada se guarda sin sanear.
 */
class AgentRegistry
{
    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): ?ShieldAgent
    {
        $host = (string) ($data['host'] ?? '');

        if ($host === '' || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $host)) {
            return null;
        }

        $ips = [];

        foreach ((array) ($data['ips'] ?? []) as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }

        $ports = static fn ($v): string => is_string($v) && preg_match('/^[0-9:,]*$/', $v) ? $v : '';
        $int = static fn ($v): int => is_numeric($v) ? max(0, (int) $v) : 0;
        $version = preg_match('/^[A-Za-z0-9._-]{1,32}$/', (string) ($data['version'] ?? '')) ? (string) $data['version'] : 'desconocida';

        $payload = [
            'active' => (bool) ($data['active'] ?? false),
            'mode' => ($data['mode'] ?? '') === 'local' ? 'local' : 'http',
            'tcp' => $ports($data['tcp'] ?? ''),
            'udp' => $ports($data['udp'] ?? ''),
            'attack' => $int($data['attack'] ?? 0) === 1,
            'conn_limit' => $int($data['conn_limit'] ?? 0),
            'syn_rate' => $int($data['syn_rate'] ?? 0),
            'udp_rate' => $int($data['udp_rate'] ?? 0),
            'ban_seconds' => $int($data['ban_seconds'] ?? 0),
            'bans_local' => $int($data['bans_local'] ?? 0),
            'bans_panel' => $int($data['bans_panel'] ?? 0),
            'dropped' => $int($data['dropped'] ?? 0),
            'tcp_pkts' => $int($data['tcp_pkts'] ?? 0),
            'udp_pkts' => $int($data['udp_pkts'] ?? 0),
            'syn' => $int($data['syn'] ?? 0),
        ];

        $agent = ShieldAgent::query()->firstOrNew(['host' => $host]);
        $agent->ips = array_slice($ips, 0, 16);
        $agent->version = $version;
        $agent->payload = $payload;
        $agent->last_seen_at = Carbon::now();
        $agent->save();

        $this->sample($host, $payload);

        return $agent;
    }

    /**
     * Guarda una muestra de contadores para las graficas (maximo una por minuto
     * y agente). Un fallo aqui nunca debe impedir registrar el latido.
     *
     * @param array<string, mixed> $payload
     */
    protected function sample(string $host, array $payload): void
    {
        try {
            $last = ShieldMetric::query()->where('host', $host)->orderByDesc('id')->first(['recorded_at']);

            if ($last !== null && time() - $last->recorded_at->getTimestamp() < 55) {
                return;
            }

            ShieldMetric::query()->create([
                'host' => $host,
                'recorded_at' => Carbon::now(),
                'dropped' => $payload['dropped'],
                'syn' => $payload['syn'],
                'tcp_pkts' => $payload['tcp_pkts'],
                'udp_pkts' => $payload['udp_pkts'],
                'bans' => $payload['bans_local'] + $payload['bans_panel'],
            ]);
        } catch (\Throwable $e) {
            // Sin tabla (migracion pendiente) o BD ocupada: se ignora.
        }
    }
}
