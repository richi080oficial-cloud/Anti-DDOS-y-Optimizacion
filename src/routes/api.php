<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api\FeedController;
use Pterodactyl\Extensions\AntiDDoS\Http\Middleware\ShieldApiKey;

/*
 * Feed de bloqueos para los agentes de nodo.
 * Prefijo propio para no interferir con la Application API de Pterodactyl.
 */
Route::middleware(['api', 'throttle:120,1', ShieldApiKey::class])
    ->prefix('api/antiddos')
    ->name('antiddos.api.')
    ->group(function () {
        Route::get('/feed', FeedController::class)->name('feed');
    });
