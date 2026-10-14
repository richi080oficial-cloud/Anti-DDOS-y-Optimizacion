<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;

class RateCounter
{
    public function hit(string $bucket, string $ip, int $window = 60): int
    {
        $key = sprintf('antiddos:rl:%s:%s:%d', $bucket, $ip, intdiv(time(), $window));

        Cache::add($key, 0, $window + 5);

        return (int) Cache::increment($key);
    }
}
