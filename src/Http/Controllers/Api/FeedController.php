<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;

class FeedController extends Controller
{
    public function __construct(protected BlockManager $blocks)
    {
    }

    public function __invoke(Request $request): Response
    {
        return new Response($this->blocks->feed((string) $request->query('host', '')), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
