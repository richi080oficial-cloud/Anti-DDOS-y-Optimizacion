<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IP bloqueada por el escudo del panel. expires_at = null significa permanente.
 */
class ShieldBlock extends Model
{
    protected $table = 'antiddos_blocks';

    protected $fillable = ['ip', 'reason', 'source', 'strikes', 'user_id', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'strikes' => 'integer',
    ];

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
