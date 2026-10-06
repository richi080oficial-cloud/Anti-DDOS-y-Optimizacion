<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Red bloqueada entera: un operador (kind = asn, value = 199585) o un pais
 * (kind = country, value = PL). expires_at = null significa permanente.
 */
class ShieldNetBlock extends Model
{
    protected $table = 'antiddos_net_blocks';

    protected $fillable = [
        'kind', 'value', 'name', 'reason', 'prefixes', 'prefix_count', 'ip_count',
        'source', 'user_id', 'expires_at', 'refreshed_at',
    ];

    protected $casts = [
        'prefixes' => 'array',
        'prefix_count' => 'integer',
        'ip_count' => 'integer',
        'expires_at' => 'datetime',
        'refreshed_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** Nombre legible: «AS199585 · TELNET Ropczyce» o «País PL». */
    public function label(): string
    {
        if ($this->kind === 'country') {
            return 'País ' . $this->value;
        }

        return 'AS' . $this->value . ($this->name ? ' · ' . $this->name : '');
    }
}
