<?php

namespace Pterodactyl\Extensions\AntiDDoS\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class Sidebar
{
    public const ID = 'antiddos';

    private const PINNED = 'vexahub::pinned';
    private const HIDE_MENU = 'vexahub::hide_menu';

    private static ?array $rows = null;

    public static function hub(): bool
    {
        try {
            return Route::has('admin.extensions.vexahub.index');
        } catch (\Throwable) {
            return false;
        }
    }

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

    public static function pinned(): bool
    {
        try {
            return in_array(self::ID, self::ids((string) (self::rows()[self::PINNED] ?? '')), true);
        } catch (\Throwable) {
            return false;
        }
    }

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

    private static function rows(): array
    {
        return self::$rows ??= DB::table('settings')
            ->whereIn('key', [self::PINNED, self::HIDE_MENU])
            ->pluck('value', 'key')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    private static function ids(string $json): array
    {
        $list = json_decode($json, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
