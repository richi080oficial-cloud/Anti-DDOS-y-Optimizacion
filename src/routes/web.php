<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin\AdminController;
use Pterodactyl\Http\Middleware\AdminAuthenticate;

/*
 * Rutas de administracion: /admin/extensions/antiddos
 *
 * El prefijo cuelga de /admin/extensions/ (y el nombre de ruta de
 * admin.extensions.) a proposito: algunos temas resaltan "Servers" en el menu
 * con una comprobacion de prefijo y un nombre que empezara por "admin/servers"
 * o "admin.servers" activaria esa seccion por error. Anti-DDoS y Optimización no empieza
 * asi, pero mantiene la misma convencion que el resto de plugins de Vexa Studio.
 */
Route::middleware(['web', 'auth', AdminAuthenticate::class])
    ->prefix('admin/extensions/antiddos')
    ->name('admin.extensions.antiddos.')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::patch('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
        Route::patch('/firewall', [AdminController::class, 'updateFirewall'])->name('firewall.update');
        Route::post('/attack', [AdminController::class, 'attackMode'])->name('attack');

        Route::post('/blocks', [AdminController::class, 'ban'])
            ->middleware('throttle:60,1')
            ->name('blocks.store');
        Route::delete('/blocks', [AdminController::class, 'unban'])->name('blocks.destroy');

        Route::post('/apikey', [AdminController::class, 'rotateKey'])->name('apikey');

        // Aplicar/revertir optimizaciones habla con Wings por cada servidor:
        // se limita el ritmo de envios.
        Route::post('/optimize', [AdminController::class, 'optimize'])
            ->middleware('throttle:10,1')
            ->name('optimize');
        Route::post('/optimize/revert', [AdminController::class, 'revert'])
            ->middleware('throttle:10,1')
            ->name('optimize.revert');

        Route::delete('/events', [AdminController::class, 'clearEvents'])->name('events.clear');
    });
