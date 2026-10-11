<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api\FeedController;
use Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api\HeartbeatController;
use Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Api\LiveController;
use Pterodactyl\Extensions\AntiDDoS\Http\Middleware\ShieldApiKey;

Route::middleware(['api', 'throttle:300,1', ShieldApiKey::class])
    ->prefix('api/antiddos')
    ->name('antiddos.api.')
    ->group(function () {
        Route::get('/feed', FeedController::class)->name('feed');
        Route::post('/heartbeat', HeartbeatController::class)->name('heartbeat');
        Route::post('/live', LiveController::class)->name('live');
    });
