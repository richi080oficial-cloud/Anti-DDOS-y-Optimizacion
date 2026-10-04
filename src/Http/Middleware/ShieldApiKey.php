<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

/**
 * Autentica a los nodos que descargan la lista de bloqueos.
 *
 * La clave se envia en la cabecera X-Antiddos-Key (o como Bearer token) y se
 * compara contra el hash guardado en la base de datos. Sin clave configurada
 * la API queda cerrada (la instalacion genera una automaticamente la primera
 * vez: ver antiddos:manage apikey --if-missing).
 */
class ShieldApiKey
{
    public function handle(Request $request, Closure $next): mixed
    {
        $provided = (string) ($request->header('X-Antiddos-Key') ?? $request->bearerToken() ?? '');
        $hash = (string) (ShieldSetting::read('api_key_hash') ?? '');

        if ($hash === '') {
            return new JsonResponse([
                'error' => 'La API de Anti-DDoS y Optimización no esta configurada. Ejecuta: antiddos apikey',
            ], 503);
        }

        if ($provided === '' || !password_verify($provided, $hash)) {
            return new JsonResponse(['error' => 'Clave de API invalida.'], 401);
        }

        return $next($request);
    }
}
