<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;

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
