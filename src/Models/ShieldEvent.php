<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de eventos de Anti-DDoS y Optimización (bloqueos, optimizaciones, cambios).
 */
class ShieldEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'antiddos_events';

    protected $fillable = ['type', 'ip', 'message', 'context'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Anota un evento. Nunca lanza: el registro no puede romper una peticion.
     *
     * @param array<string, mixed> $context
     */
    public static function record(string $type, string $message, ?string $ip = null, array $context = []): void
    {
        try {
            if (!ShieldSetting::read('log_events', true)) {
                return;
            }

            self::query()->create([
                'type' => $type,
                'ip' => $ip,
                'message' => mb_substr($message, 0, 500),
                'context' => $context === [] ? null : $context,
            ]);
        } catch (\Throwable $e) {
            // Sin tabla (migraciones pendientes) o BD caida: se ignora.
        }
    }
}
