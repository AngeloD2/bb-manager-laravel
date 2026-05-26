<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackLog extends Model
{
    use HasUuids;

    protected $table = 'playback_logs';

    // No updated_at — logs are immutable once written.
    public $timestamps = false;

    protected $fillable = [
        'asset_id',
        'folder_id',
        'device_id',
        'token_spent',
        'was_override',
        'played_at',
    ];

    protected $casts = [
        'token_spent'  => 'integer',
        'was_override' => 'boolean',
        'played_at'    => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'asset_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
