<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgentBundleController extends Controller
{
    public function __invoke(): BinaryFileResponse|JsonResponse
    {
        $path = storage_path('app/antiddos/agent-bundle.tar.gz');

        if (!is_file($path)) {
            return new JsonResponse([
                'error' => 'Todavia no hay un paquete del agente preparado. Actualiza el panel primero: sudo antiddos update',
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="antiddos-agent.tar.gz"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
