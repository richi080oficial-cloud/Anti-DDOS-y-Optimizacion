<?php

namespace Pterodactyl\Extensions\AntiDDoS\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Contador de peticiones por IP en ventana fija, sobre la cache del panel
 * (Redis en una instalacion normal de Pterodactyl). Una sola operacion atomica
 * por peticion y sin SQL.
 */
class RateCounter
{
    /**
     * Suma una peticion al cubo y devuelve el total de la ventana actual.
     */
    public function hit(string $bucket, string $ip, int $window = 60): int
    {
        $key = sprintf('antiddos:rl:%s:%s:%d', $bucket, $ip, intdiv(time(), $window));

        // add() crea la clave solo si no existe, con su caducidad; increment()
        // es atomico en Redis/Memcached/database y no renueva el TTL.
        Cache::add($key, 0, $window + 5);

        return (int) Cache::increment($key);
    }
}
