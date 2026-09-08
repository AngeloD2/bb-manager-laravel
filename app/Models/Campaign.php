<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An advertiser's booking: a named period that owns the Loops running during it.
 */
class Campaign extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'starts_on',
        'ends_on',
    ];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on'   => 'date:Y-m-d',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function loops(): HasMany
    {
        return $this->hasMany(MediaLoop::class, 'campaign_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** True when the given date falls inside this campaign's window. */
    public function isRunningOn(\Carbon\Carbon $date): bool
    {
        if ($this->starts_on !== null && $date->lt($this->starts_on->copy()->startOfDay())) {
            return false;
        }
        if ($this->ends_on !== null && $date->gt($this->ends_on->copy()->endOfDay())) {
            return false;
        }

        return true;
    }
}
