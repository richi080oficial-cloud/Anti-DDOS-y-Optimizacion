<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin\AdminController;
use Pterodactyl\Http\Middleware\AdminAuthenticate;

Route::middleware(['web', 'auth', AdminAuthenticate::class])
    ->prefix('admin/extensions/antiddos')
    ->name('admin.extensions.antiddos.')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/sidebar', \Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin\SidebarController::class)->name('sidebar');
        Route::patch('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
        Route::patch('/firewall', [AdminController::class, 'updateFirewall'])->name('firewall.update');
        Route::post('/attack', [AdminController::class, 'attackMode'])->name('attack');

        Route::post('/blocks', [AdminController::class, 'ban'])
            ->middleware('throttle:60,1')
            ->name('blocks.store');
        Route::delete('/blocks', [AdminController::class, 'unban'])->name('blocks.destroy');

        Route::post('/networks', [AdminController::class, 'blockNetwork'])->middleware('throttle:20,1')->name('networks.store');
        Route::delete('/networks', [AdminController::class, 'unblockNetwork'])->name('networks.destroy');
        Route::post('/networks/refresh', [AdminController::class, 'refreshNetworks'])->middleware('throttle:6,1')->name('networks.refresh');

        Route::post('/apikey', [AdminController::class, 'rotateKey'])->name('apikey');

        Route::patch('/intel', [AdminController::class, 'updateIntel'])->name('intel.update');
        Route::post('/intel/refresh', [AdminController::class, 'refreshLists'])->middleware('throttle:6,1')->name('intel.refresh');
        Route::patch('/policy', [AdminController::class, 'updatePolicy'])->name('policy.update');
        Route::post('/intel/recheck', [AdminController::class, 'recheckIp'])->middleware('throttle:20,1')->name('intel.recheck');
        Route::post('/whitelist', [AdminController::class, 'whitelistIp'])->middleware('throttle:30,1')->name('whitelist.add');

        Route::get('/traffic', [AdminController::class, 'trafficData'])->middleware('throttle:300,1')->name('traffic');

        Route::post('/ports/change', [AdminController::class, 'changePort'])->middleware('throttle:20,1')->name('ports.change');
        Route::post('/ports/auto', [AdminController::class, 'autoPorts'])->middleware('throttle:10,1')->name('ports.auto');

        Route::delete('/events', [AdminController::class, 'clearEvents'])->name('events.clear');
    });
