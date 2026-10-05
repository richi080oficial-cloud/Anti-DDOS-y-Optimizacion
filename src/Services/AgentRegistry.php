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
            'tcp_bytes' => $int($data['tcp_bytes'] ?? 0),
            'udp_bytes' => $int($data['udp_bytes'] ?? 0),
            'drop_bytes' => $int($data['drop_bytes'] ?? 0),
            'd_ban' => $int($data['d_ban'] ?? 0),
            'd_mal' => $int($data['d_mal'] ?? 0),
            'd_icmp' => $int($data['d_icmp'] ?? 0),
            'd_syn' => $int($data['d_syn'] ?? 0),
            'd_udp' => $int($data['d_udp'] ?? 0),
            'd_conn' => $int($data['d_conn'] ?? 0),
            'd_web' => $int($data['d_web'] ?? 0),
            'rx_bytes' => $int($data['rx_bytes'] ?? 0),
            'rx_pkts' => $int($data['rx_pkts'] ?? 0),
            'tx_bytes' => $int($data['tx_bytes'] ?? 0),
            'tx_pkts' => $int($data['tx_pkts'] ?? 0),
            'ban_mode' => in_array($data['ban_mode'] ?? '', ['monitor', 'balanced', 'strict'], true) ? $data['ban_mode'] : 'balanced',
            'fw_bans' => $this->fwBans($data['fw_bans'] ?? []),
            'top_ips' => $this->topIps($data['top_ips'] ?? []),
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
     * Guarda una muestra de contadores para las graficas (como maximo una cada
     * ~25 s por agente). Un fallo aqui nunca debe impedir registrar el latido.
     *
     * @param array<string, mixed> $payload
     */
    protected function sample(string $host, array $payload): void
    {
        try {
            $last = ShieldMetric::query()->where('host', $host)->orderByDesc('id')->first(['recorded_at']);

            if ($last !== null && time() - $last->recorded_at->getTimestamp() < 25) {
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
                'tcp_bytes' => $payload['tcp_bytes'],
                'udp_bytes' => $payload['udp_bytes'],
                'drop_bytes' => $payload['drop_bytes'],
                'd_ban' => $payload['d_ban'],
                'd_mal' => $payload['d_mal'],
                'd_icmp' => $payload['d_icmp'],
                'd_syn' => $payload['d_syn'],
                'd_udp' => $payload['d_udp'],
                'd_conn' => $payload['d_conn'],
                'd_web' => $payload['d_web'],
                'rx_bytes' => $payload['rx_bytes'],
                'rx_pkts' => $payload['rx_pkts'],
                'tx_bytes' => $payload['tx_bytes'],
                'tx_pkts' => $payload['tx_pkts'],
            ]);
        } catch (\Throwable $e) {
            // Sin tabla (migracion pendiente) o BD ocupada: se ignora.
        }
    }

    /**
     * Las IPs con mas conexiones que informa el agente, saneadas.
     *
     * @param mixed $list
     * @return array<int, array{ip: string, conns: int}>
     */
    protected function topIps(mixed $list): array
    {
        $out = [];

        foreach (is_array($list) ? $list : [] as $row) {
            $ip = is_array($row) ? ($row['ip'] ?? null) : null;

            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && is_numeric($row['conns'] ?? null)) {
                $out[] = ['ip' => $ip, 'conns' => max(0, (int) $row['conns'])];
            }
        }

        return array_slice($out, 0, 10);
    }

    /**
     * Bloqueos locales activos del firewall, con motivo y tiempo restante.
     *
     * @param mixed $list
     * @return array<int, array{ip: string, left: int, why: string}>
     */
    protected function fwBans(mixed $list): array
    {
        $out = [];

        foreach (is_array($list) ? $list : [] as $row) {
            $ip = is_array($row) ? ($row['ip'] ?? null) : null;
            $why = is_array($row) ? ($row['why'] ?? '') : '';

            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && in_array($why, ['syn', 'ssh', 'manual'], true)) {
                $out[] = ['ip' => $ip, 'left' => max(0, (int) ($row['left'] ?? 0)), 'why' => $why];
            }
        }

        return array_slice($out, 0, 30);
    }
}
