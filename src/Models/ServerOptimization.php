<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

/**
 * Estado de optimizacion de un servidor: perfil aplicado y lo necesario para
 * revertirlo (comando de arranque original y archivos modificados).
 */
class ServerOptimization extends Model
{
    protected $table = 'antiddos_optimizations';

    protected $fillable = ['server_id', 'profile', 'original_startup', 'files'];

    protected $casts = [
        'files' => 'array',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
