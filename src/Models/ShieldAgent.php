<?php

namespace Pterodactyl\Extensions\AntiDDoS\Models;

use Illuminate\Database\Eloquent\Model;

class ShieldAgent extends Model
{
    protected $table = 'antiddos_agents';

    protected $fillable = ['host', 'ips', 'version', 'payload', 'last_seen_at'];

    protected $casts = [
        'ips' => 'array',
        'payload' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public const STALE_AFTER = 150;

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->secondsSinceSeen() <= self::STALE_AFTER;
    }

    public function secondsSinceSeen(): int
    {
        return $this->last_seen_at === null ? PHP_INT_MAX : max(0, time() - $this->last_seen_at->getTimestamp());
    }
}
