<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use HasUuids, HasApiTokens;

    protected $fillable = [
        'name',
        'location',
        'geo_zone',
        'is_online',
        'last_seen_at',
    ];

    protected $casts = [
        'is_online'    => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function playbackLogs(): HasMany
    {
        return $this->hasMany(PlaybackLog::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(TimelineOverride::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Mark this device as recently seen and online. */
    public function heartbeat(): void
    {
        $this->update([
            'is_online'    => true,
            'last_seen_at' => now(),
        ]);
    }

    /** Unconsumed override commands waiting for this board. */
    public function pendingOverrides(): HasMany
    {
        return $this->overrides()->where('consumed', false)->orderBy('created_at');
    }
}
