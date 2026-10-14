<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ShieldSetting extends Model
{
    public const CACHE_KEY = 'antiddos:settings';

    protected $table = 'antiddos_settings';

    protected $fillable = ['key', 'value'];

    public static function snapshot(): array
    {
        $defaults = (array) config('antiddos.defaults', []);

        $stored = Cache::remember(self::CACHE_KEY, 300, function () {
            try {
                return self::query()->pluck('value', 'key')->all();
            } catch (\Throwable $e) {
                return [];
            }
        });

        $out = [];
        foreach ($defaults as $key => $default) {
            $value = array_key_exists($key, $stored) ? $stored[$key] : $default;
            $out[$key] = self::cast($value, $default);
        }

        return $out;
    }

    public static function read(string $key, mixed $fallback = null): mixed
    {
        $snapshot = self::snapshot();

        if (array_key_exists($key, $snapshot)) {
            return $snapshot[$key];
        }

        try {
            $row = self::query()->where('key', $key)->first();
        } catch (\Throwable $e) {
            return $fallback;
        }

        return $row ? $row->value : $fallback;
    }

    public static function write(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            self::query()->updateOrCreate(['key' => $key], ['value' => is_null($value) ? null : (string) $value]);
        }

        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function cast(mixed $value, mixed $default): mixed
    {
        if (is_bool($default)) {
            return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
        }

        if (is_int($default)) {
            return (int) $value;
        }

        return is_null($value) ? $default : (string) $value;
    }
}
