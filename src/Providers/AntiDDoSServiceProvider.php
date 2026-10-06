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

/**
 * Punto de entrada de la extension. Todo (vistas, rutas, migraciones y config)
 * se carga desde este directorio: no hace falta publicar nada en el panel.
 */
class AntiDDoSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/antiddos.php', 'antiddos');

        $this->app->singleton(BlockManager::class);
        $this->app->singleton(RateCounter::class);
        $this->app->singleton(NetworkBlocks::class);

        // Las rutas se cargan aqui, en register(), y no en boot(): el panel de
        // cliente de Pterodactyl es una SPA servida por una ruta "catch-all" que
        // se registra en el boot() de su RouteServiceProvider, y Laravel usa la
        // PRIMERA ruta que coincide. Cargandolas en register() (que se ejecuta
        // antes que cualquier boot()) se garantiza que /admin/extensions/antiddos
        // y /api/antiddos/* queden registradas antes del catch-all.
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'antiddos');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Escudo de capa 7: middleware GLOBAL. Se anade al final de la pila global
        // (despues de TrustProxies, para ver la IP real detras de un proxy) y antes
        // de sesion/BD, que son middleware de grupo. Ver ShieldGuard.
        if (!$this->app->runningInConsole()) {
            $this->app->make(Kernel::class)->pushMiddleware(ShieldGuard::class);
        }

        // Garantiza el enlace del menu lateral del admin aunque el parche de
        // la vista layouts/admin.blade.php no este aplicado (temas de
        // terceros, actualizaciones del panel...). Ver AdminSidebarLink.
        $this->app->make('router')->pushMiddlewareToGroup('web', AdminSidebarLink::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ShieldCommand::class]);
        }

        // Limpieza diaria de bloqueos caducados y eventos antiguos. Usa el
        // scheduler del panel (el cron "schedule:run" que ya exige Pterodactyl).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('antiddos:manage prune')->dailyAt('04:30')->withoutOverlapping();
            $schedule->command('antiddos:manage refresh-lists')->dailyAt('05:15')->withoutOverlapping();
            // Rangos nuevos que anuncien los operadores/paises bloqueados.
            $schedule->command('antiddos:manage refresh-nets')->dailyAt('05:40')->withoutOverlapping();
            // Historial de la grafica: 1 muestra cada 5 min pasadas 26 h y nada de mas de 8 dias.
            $schedule->command('antiddos:manage compact-metrics')->hourlyAt(17)->withoutOverlapping();
        });
    }
}
