<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Almacen clave/valor de la configuracion de Anti-DDoS y Optimización.
 *
 * Se usan nombres de metodo propios (read/write/snapshot) para no colisionar
 * con los metodos estaticos que Eloquent reenvia al query builder.
 */
class ShieldSetting extends Model
{
    public const CACHE_KEY = 'antiddos:settings';

    protected $table = 'antiddos_settings';

    protected $fillable = ['key', 'value'];

    /**
     * Configuracion completa (defaults del archivo de config + overrides de BD),
     * ya convertida a los tipos correctos.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $defaults = (array) config('antiddos.defaults', []);

        $stored = Cache::remember(self::CACHE_KEY, 300, function () {
            try {
                return self::query()->pluck('value', 'key')->all();
            } catch (\Throwable $e) {
                // La tabla puede no existir todavia (por ejemplo durante migrate).
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

    /**
     * Lee un unico valor de configuracion.
     */
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

    /**
     * Escribe uno o varios valores y limpia la cache.
     *
     * @param array<string, mixed> $values
     */
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
