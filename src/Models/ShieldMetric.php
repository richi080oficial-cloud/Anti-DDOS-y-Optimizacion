<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;

class ShieldMetric extends Model
{
    public $timestamps = false;

    protected $table = 'antiddos_metrics';

    protected $fillable = [
        'host', 'recorded_at', 'dropped', 'syn', 'tcp_pkts', 'udp_pkts', 'bans',
        'tcp_bytes', 'udp_bytes', 'drop_bytes', 'd_ban', 'd_mal', 'd_icmp', 'd_syn', 'd_udp', 'd_conn', 'd_web',
        'rx_bytes', 'rx_pkts', 'tx_bytes', 'tx_pkts',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'dropped' => 'integer',
        'syn' => 'integer',
        'tcp_pkts' => 'integer',
        'udp_pkts' => 'integer',
        'bans' => 'integer',
    ];
}
