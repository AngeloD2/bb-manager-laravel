<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaFolder extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'media_folders';

    protected $fillable = [
        'name',
        'parent_folder_id',
        'is_fallback',
        'max_daily_tokens',
    ];

    protected $casts = [
        'is_fallback'      => 'boolean',
        'max_daily_tokens' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'parent_folder_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MediaFolder::class, 'parent_folder_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Total tokens spent today across all assets in this folder.
     * Used by ConstraintValidationService for macro-level cap enforcement.
     */
    public function tokensSpentToday(): int
    {
        return PlaybackLog::whereIn(
            'asset_id',
            $this->assets()->pluck('id')
        )
        ->where('played_at', '>=', now()->startOfDay())
        ->sum('token_spent');
    }

    /** Is this folder at or over its daily cap? */
    public function isDailyCapped(): bool
    {
        if ($this->max_daily_tokens === null) {
            return false;
        }

        return $this->tokensSpentToday() >= $this->max_daily_tokens;
    }
}
