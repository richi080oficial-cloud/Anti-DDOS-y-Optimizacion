<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;

/**
 * Lista de bloqueos y lista blanca para los agentes de nodo.
 *
 * Formato texto plano, una entrada por linea:
 *   B 203.0.113.7        IP bloqueada
 *   W 198.51.100.0/24    IP o rango en lista blanca
 *
 * El agente la descarga cada 30 s (antiddos-sync.timer) y la carga en un
 * set de ipset de forma atomica.
 */
class FeedController extends Controller
{
    public function __construct(protected BlockManager $blocks)
    {
    }

    public function __invoke(): Response
    {
        return new Response($this->blocks->feed(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
