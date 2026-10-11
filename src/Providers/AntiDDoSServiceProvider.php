<?php

namespace Pterodactyl\Extensions\AntiDDoS\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\AntiDDoS\Console\Commands\ShieldCommand;
use Pterodactyl\Extensions\AntiDDoS\Http\Middleware\AdminSidebarLink;
use Pterodactyl\Extensions\AntiDDoS\Http\Middleware\ShieldGuard;
use Pterodactyl\Extensions\AntiDDoS\Services\BlockManager;
use Pterodactyl\Extensions\AntiDDoS\Services\NetworkBlocks;
use Pterodactyl\Extensions\AntiDDoS\Services\RateCounter;

class AntiDDoSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/antiddos.php', 'antiddos');

        $this->app->singleton(BlockManager::class);
        $this->app->singleton(RateCounter::class);
        $this->app->singleton(NetworkBlocks::class);

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'antiddos');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if (!$this->app->runningInConsole()) {
            $this->app->make(Kernel::class)->pushMiddleware(ShieldGuard::class);
        }

        $this->app->make('router')->pushMiddlewareToGroup('web', AdminSidebarLink::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ShieldCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('antiddos:manage prune')->dailyAt('04:30')->withoutOverlapping();
            $schedule->command('antiddos:manage refresh-lists')->dailyAt('05:15')->withoutOverlapping();
            $schedule->command('antiddos:manage auto-block')->everyMinute()->runInBackground()->withoutOverlapping(5)
                ->when(fn () => app(\Pterodactyl\Extensions\AntiDDoS\Services\AutoBlocker::class)->enabled());
            $schedule->command('antiddos:manage refresh-nets')->dailyAt('05:40')->withoutOverlapping();
            $schedule->command('antiddos:manage compact-metrics')->hourlyAt(17)->withoutOverlapping();
        });
    }
}
