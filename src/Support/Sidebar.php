<?php

namespace Pterodactyl\Extensions\AntiDDoS\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Entrada de Anti-DDoS y Optimización en el menu lateral del admin.
 *
 * Con Vexa Hub instalado, las entradas de los plugins de Vexa Studio estan
 * OCULTAS por defecto: el plugin se abre desde su tarjeta en Vexa Hub. Cada plugin
 * se puede dejar a mano en el menu con su propio interruptor «Mostrar en el menu
 * lateral». Sin Vexa Hub la entrada se muestra siempre (es la unica puerta al plugin).
 *
 * Los ajustes son los de Vexa Hub (tabla "settings" del panel), asi todos los
 * plugins y Vexa Hub se ponen de acuerdo sin depender unos de otros:
 *
 *   vexahub::pinned     lista JSON con los identificadores de los plugins que se quedan en el menu
 *   vexahub::hide_menu  "0" si el administrador ha desactivado la limpieza del menu (se muestra todo)
 *
 * Ante cualquier fallo (base de datos, tabla...) la entrada se MUESTRA: nunca debe
 * quedarse un plugin sin forma de abrirlo.
 */
final class Sidebar
{
    public const ID = 'antiddos';

    private const PINNED = 'vexahub::pinned';
    private const HIDE_MENU = 'vexahub::hide_menu';

    /** @var array<string, string>|null */
    private static ?array $rows = null;

    /** ¿Esta Vexa Hub cargado en este panel? Si no, el menu es la unica puerta al plugin. */
    public static function hub(): bool
    {
        try {
            return Route::has('admin.extensions.vexahub.index');
        } catch (\Throwable) {
            return false;
        }
    }

    /** ¿Se muestra la entrada en el menu lateral? */
    public static function visible(): bool
    {
        try {
            if (!self::hub()) {
                return true;
            }

            if ((self::rows()[self::HIDE_MENU] ?? '1') === '0') {
                return true;
            }

            return self::pinned();
        } catch (\Throwable) {
            return true;
        }
    }

    /** ¿Ha dejado el administrador este plugin fijado en el menu? */
    public static function pinned(): bool
    {
        try {
            return in_array(self::ID, self::ids((string) (self::rows()[self::PINNED] ?? '')), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Deja (o quita) la entrada en el menu. No toca los demas plugins. */
    public static function set(bool $show): void
    {
        $raw = (string) DB::table('settings')->where('key', self::PINNED)->value('value');
        $ids = array_values(array_filter(self::ids($raw), fn (string $id) => $id !== self::ID));

        if ($show) {
            $ids[] = self::ID;
        }

        DB::table('settings')->updateOrInsert(['key' => self::PINNED], ['value' => json_encode($ids)]);
        self::$rows = null;
    }

    /** @return array<string, string> */
    private static function rows(): array
    {
        return self::$rows ??= DB::table('settings')
            ->whereIn('key', [self::PINNED, self::HIDE_MENU])
            ->pluck('value', 'key')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /** @return array<int, string> */
    private static function ids(string $json): array
    {
        $list = json_decode($json, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
