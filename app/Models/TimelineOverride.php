<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimelineOverride extends Model
{
    use HasUuids;

    protected $table = 'timeline_overrides';

    protected $fillable = [
        'asset_id',
        'billboard_id',
        'consumed',
        'consumed_at',
    ];

    protected $casts = [
        'consumed'    => 'boolean',
        'consumed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'asset_id');
    }

    public function billboard(): BelongsTo
    {
        return $this->belongsTo(Billboard::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function consume(): void
    {
        $this->update([
            'consumed'    => true,
            'consumed_at' => now(),
        ]);
    }
}
