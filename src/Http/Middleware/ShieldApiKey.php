<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\AntiDDoS\Models\ShieldSetting;

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
