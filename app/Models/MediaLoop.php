<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaLoop extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'media_loops';

    protected $fillable = [
        'name',
        'parent_loop_id',
        'is_fallback',
        'is_global',
        'max_daily_spots',
        'assigned_devices',
        'order_index',
    ];

    protected $casts = [
        'is_fallback'      => 'boolean',
        'is_global'        => 'boolean',
        'max_daily_spots' => 'integer',
        'assigned_devices' => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaLoop::class, 'parent_loop_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MediaLoop::class, 'parent_loop_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'loop_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Total spots spent today across all assets in this loop.
     * Used by ConstraintValidationService for macro-level cap enforcement.
     */
    public function spotsSpentToday(?string $timezone = null): int
    {
        $tz = $timezone ?? config('app.timezone', 'UTC');
        return PlaybackLog::whereIn(
            'asset_id',
            $this->assets()->pluck('id')
        )
        ->where('played_at', '>=', now($tz)->startOfDay())
        ->sum('spot_spent');
    }

    /** Is this loop at or over its daily cap? */
    public function isDailyCapped(): bool
    {
        if ($this->max_daily_spots === null) {
            return false;
        }

        return $this->spotsSpentToday() >= $this->max_daily_spots;
    }
}
