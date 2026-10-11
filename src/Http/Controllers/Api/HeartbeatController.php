<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Extensions\AntiDDoS\Services\AgentRegistry;

class HeartbeatController extends Controller
{
    public function __construct(protected AgentRegistry $agents)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        if ($this->agents->record(is_array($data) ? $data : []) === null) {
            return new JsonResponse(['error' => 'Payload no valido.'], 422);
        }

        return new JsonResponse(['ok' => true]);
    }
}
