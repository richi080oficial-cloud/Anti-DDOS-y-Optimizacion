<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldAgent;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;
use Pterodactyl\Models\Node;

class SafeIps
{
    protected const CACHE_KEY = 'antiddos:infra-ips';

    public function infrastructure(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, function () {
            $ips = [];

            try {
                foreach (Node::query()->pluck('fqdn') as $fqdn) {
                    foreach ($this->resolve((string) $fqdn) as $ip) {
                        $ips[$ip] = true;
                    }
                }

                foreach (ShieldAgent::query()->get(['ips']) as $agent) {
                    foreach ((array) $agent->ips as $ip) {
                        $ips[(string) $ip] = true;
                    }
                }
            } catch (\Throwable $e) {
            }

            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

            foreach ($this->resolve($host) as $ip) {
                $ips[$ip] = true;
            }

            return array_values(array_filter(
                array_keys($ips),
                fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && !IpMatcher::isInternal($ip)
            ));
        });
    }

    public function isInfrastructure(string $ip): bool
    {
        return in_array($ip, $this->infrastructure(), true);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
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
            return 'Está en tu lista blanca.';
        }

        if ($this->isInfrastructure($ip)) {
            return 'Es la IP de un nodo, de un agente o del propio panel.';
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
