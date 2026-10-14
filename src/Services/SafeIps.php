<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Models\Node;

class SafeIps
{
    protected const CACHE_KEY = 'antiddos:infra-auto';

    public function automatic(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, function () {
            $rows = [];

            $add = function (string $ip, string $why) use (&$rows) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || IpMatcher::isInternal($ip)) {
                    return;
                }

                $rows[$ip] = $rows[$ip] ?? $why;
            };

            try {
                foreach (Node::query()->get(['name', 'fqdn']) as $node) {
                    foreach ($this->resolve((string) $node->fqdn) as $ip) {
                        $add($ip, 'Nodo «' . $node->name . '»');
                    }
                }

                foreach (ShieldAgent::query()->get(['host', 'ips']) as $agent) {
                    foreach ((array) $agent->ips as $ip) {
                        $add((string) $ip, 'Agente «' . $agent->host . '»');
                    }
                }
            } catch (\Throwable $e) {
            }

            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

            foreach ($this->resolve($host) as $ip) {
                $add($ip, 'Dirección del panel');
            }

            foreach ($this->localAddresses() as $ip) {
                $add($ip, 'Este servidor');
            }

            $out = [];

            foreach ($rows as $ip => $why) {
                $out[] = ['ip' => (string) $ip, 'why' => $why];
            }

            return $out;
        });
    }

    public function infrastructure(): array
    {
        return array_column($this->automatic(), 'ip');
    }

    public function isInfrastructure(string $ip): bool
    {
        return in_array($ip, $this->infrastructure(), true);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function localAddresses(): array
    {
        try {
            $raw = @file_get_contents('/proc/net/fib_trie');
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $ips = [];
        $last = null;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/\|--\s+(\d+\.\d+\.\d+\.\d+)\s*$/', $line, $m) === 1) {
                $last = $m[1];

                continue;
            }

            if ($last !== null && str_contains($line, '/32 host LOCAL')) {
                $ips[$last] = true;
            }
        }

        return array_keys($ips);
    }

    public function protectedReason(string $ip, ?string $requestIp = null): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'No es una IP válida.';
        }

        if ($requestIp !== null && $ip === $requestIp) {
            return 'Es tu propia IP: te dejarías fuera del panel.';
        }

        if (IpMatcher::isInternal($ip)) {
            return 'Es una IP privada o reservada (tu red interna o Docker).';
        }

        if (IpMatcher::isCloudflare($ip)) {
            return 'Es una IP de Cloudflare: bloquearla cortaría a todos los usuarios que pasan por ese nodo de Cloudflare.';
        }

        if (IpMatcher::inList($ip, IpMatcher::parseList((string) ShieldSetting::read('whitelist', '')))) {
            return 'Está en tu lista de IPs protegidas.';
        }

        if ($this->isInfrastructure($ip)) {
            return 'Es la IP de este servidor, de un nodo, de un agente o del propio panel.';
        }

        return null;
    }

    protected function resolve(string $host): array
    {
        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $list = @gethostbynamel($host);

        return is_array($list) ? $list : [];
    }
}
