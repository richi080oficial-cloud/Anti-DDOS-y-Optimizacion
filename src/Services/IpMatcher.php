<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

final class IpMatcher
{
    public static function valid(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (!str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$net, $bits] = explode('/', $value, 2);

        $bin = @inet_pton($net);

        if ($bin === false || !ctype_digit($bits)) {
            return false;
        }

        return (int) $bits <= strlen($bin) * 8;
    }

    public static function matches(string $ip, string $rule): bool
    {
        $ipBin = @inet_pton($ip);

        if ($ipBin === false) {
            return false;
        }

        $rule = trim($rule);

        if (!str_contains($rule, '/')) {
            $ruleBin = @inet_pton($rule);

            return $ruleBin !== false && $ruleBin === $ipBin;
        }

        [$net, $bits] = explode('/', $rule, 2);
        $netBin = @inet_pton($net);

        if ($netBin === false || strlen($netBin) !== strlen($ipBin) || !ctype_digit($bits)) {
            return false;
        }

        $bits = min((int) $bits, strlen($netBin) * 8);
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    public static function inList(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    public static function parseList(string $text): array
    {
        $out = [];

        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if ($line !== '' && self::valid($line)) {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    public const CLOUDFLARE_V4 = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    public static function isCloudflare(string $ip): bool
    {
        return self::inList($ip, self::CLOUDFLARE_V4);
    }

    public static function isInternal(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
