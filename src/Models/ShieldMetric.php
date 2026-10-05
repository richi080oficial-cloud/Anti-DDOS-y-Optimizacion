<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Muestra de los contadores de un agente (una por minuto como maximo).
 */
class ShieldMetric extends Model
{
    public $timestamps = false;

    protected $table = 'antiddos_metrics';

    protected $fillable = ['host', 'recorded_at', 'dropped', 'syn', 'tcp_pkts', 'udp_pkts', 'bans'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'dropped' => 'integer',
        'syn' => 'integer',
        'tcp_pkts' => 'integer',
        'udp_pkts' => 'integer',
        'bans' => 'integer',
    ];
}
