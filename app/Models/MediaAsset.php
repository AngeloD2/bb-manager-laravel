<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'media_assets';

    protected $fillable = [
        'name',
        'file_path',
        'file_type',
        'folder_id',
        'size_bytes',
        'duration_secs',
        'geo_campaign',
        'campaign_name',
        'is_synced',
        'max_plays_per_hour',
        'max_daily_plays',
        'play_tokens_remaining',
    ];

    protected $casts = [
        'is_synced'             => 'boolean',
        'size_bytes'            => 'integer',
        'duration_secs'         => 'integer',
        'max_plays_per_hour'    => 'integer',
        'max_daily_plays'       => 'integer',
        'play_tokens_remaining' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function playbackLogs(): HasMany
    {
        return $this->hasMany(PlaybackLog::class, 'asset_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Generates a CloudFront-signed or S3 presigned URL for CDN delivery
     * to the physical billboard device.
     */
    public function deliveryUrl(int $expirySeconds = 3600): string
    {
        $cdnBase = config('filesystems.cloudfront_url');

        if ($cdnBase) {
            return rtrim($cdnBase, '/') . '/' . $this->file_path;
        }

        return Storage::disk('s3')->temporaryUrl(
            $this->file_path,
            now()->addSeconds($expirySeconds)
        );
    }

    /** Plays in the last hour for constraint checking. */
    public function playsLastHour(): int
    {
        return $this->playbackLogs()
            ->where('played_at', '>=', now()->subHour())
            ->count();
    }

    /** Plays today for daily cap enforcement. */
    public function playsToday(): int
    {
        return $this->playbackLogs()
            ->where('played_at', '>=', now()->startOfDay())
            ->count();
    }

    /** Whether this is a fallback/filler asset. */
    public function isFallback(): bool
    {
        return $this->folder?->is_fallback ?? false;
    }

    /** Deduct tokens; clamp at zero. */
    public function deductToken(): void
    {
        $this->decrement('play_tokens_remaining');
        if ($this->play_tokens_remaining < 0) {
            $this->update(['play_tokens_remaining' => 0]);
        }
    }
}
