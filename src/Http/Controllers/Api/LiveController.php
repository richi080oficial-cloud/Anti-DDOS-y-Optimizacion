<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Extensions\AntiDDoS\Services\LiveTraffic;

/**
 * Muestra de contadores (cada 3 s) de un agente que esta en OTRA maquina que el
 * panel. Va a la cache (Redis), nunca a la base de datos. Autenticado con la
 * clave de la API.
 */
class LiveController extends Controller
{
    public function __construct(protected LiveTraffic $live)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        if (!$this->live->push(is_array($data) ? $data : [])) {
            return new JsonResponse(['error' => 'Payload no valido.'], 422);
        }

        return new JsonResponse(['ok' => true]);
    }
}
