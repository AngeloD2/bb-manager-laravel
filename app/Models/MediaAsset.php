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
        'loop_id',
        'order_index',
        'size_bytes',
        'duration_secs',
        'geo_campaign',
        'campaign_name',
        'is_synced',
        'is_global',
        'max_plays_per_hour',
        'max_daily_plays',
        'play_spots_remaining',
        'assigned_devices',
        'campaign_start_date',
        'campaign_end_date',
        'playback_times',
        'sync_error',
    ];

    protected $casts = [
        'is_synced'             => 'boolean',
        'is_global'             => 'boolean',
        'size_bytes'            => 'integer',
        'duration_secs'         => 'integer',
        'max_plays_per_hour'    => 'integer',
        'max_daily_plays'       => 'integer',
        'play_spots_remaining'  => 'integer',
        'assigned_devices'      => 'array',
        'campaign_start_date'   => 'date:Y-m-d',
        'campaign_end_date'     => 'date:Y-m-d',
        'playback_times'        => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function loop(): BelongsTo
    {
        return $this->belongsTo(MediaLoop::class, 'loop_id');
    }

    public function playbackLogs(): HasMany
    {
        return $this->hasMany(PlaybackLog::class, 'asset_id');
    }

    public function conflicts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'asset_conflicts', 'asset_id_1', 'asset_id_2')
            ->withTimestamps();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Generates a CloudFront-signed or S3 presigned URL for CDN delivery
     * to the physical billboard device.
     */
    public function deliveryUrl(int $expirySeconds = 3600): string
    {
        $cdnBase = config('media.cloudfront_url');

        if ($cdnBase) {
            return rtrim($cdnBase, '/') . '/' . $this->file_path;
        }

        try {
            if (config('filesystems.default') === 's3' || env('AWS_BUCKET')) {
                return Storage::disk('s3')->temporaryUrl(
                    $this->file_path,
                    now()->addSeconds($expirySeconds)
                );
            }
        } catch (\Exception $e) {}

        // Fallback for local development if S3 is not configured
        return url('/storage/' . $this->file_path);
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

    /** True when today falls within the optional campaign flight window. */
    public function isWithinCampaignPeriod(\Carbon\Carbon $date): bool
    {
        if ($this->campaign_start_date === null && $this->campaign_end_date === null) {
            return true;
        }
        if ($this->campaign_start_date !== null && $date->lt($this->campaign_start_date->copy()->startOfDay())) {
            return false;
        }
        if ($this->campaign_end_date !== null && $date->gt($this->campaign_end_date->copy()->endOfDay())) {
            return false;
        }
        return true;
    }

    /**
     * True when the current time is within $toleranceMinutes of any listed
     * playback slot. Returns true when no slots are configured.
     */
    public function isWithinPlaybackWindow(\Carbon\Carbon $now, int $toleranceMinutes = 15): bool
    {
        if (empty($this->playback_times)) {
            return true;
        }
        $currentMinutes = $now->hour * 60 + $now->minute;
        foreach ($this->playback_times as $slot) {
            [$h, $m] = explode(':', $slot);
            $slotMinutes = (int) $h * 60 + (int) $m;
            $diff = abs($currentMinutes - $slotMinutes);
            // Wrap-around at midnight (e.g. 23:55 vs 00:05)
            if (min($diff, 1440 - $diff) <= $toleranceMinutes) {
                return true;
            }
        }
        return false;
    }

    /** Whether this is a fallback/filler asset. */
    public function isFallback(): bool
    {
        return $this->loop?->is_fallback ?? false;
    }

    /** Deduct spots; clamp at zero. */
    public function deductSpot(): void
    {
        $this->decrement('play_spots_remaining');
        if ($this->play_spots_remaining < 0) {
            $this->update(['play_spots_remaining' => 0]);
        }
    }

    /** Ensure conflicts are stored symmetrically for fast querying */
    public function syncConflicts(array $conflictAssetIds): void
    {
        \Illuminate\Support\Facades\DB::table('asset_conflicts')
            ->where('asset_id_1', $this->id)
            ->orWhere('asset_id_2', $this->id)
            ->delete();

        $inserts = [];
        $now = now();
        foreach (array_unique($conflictAssetIds) as $otherId) {
            if ($otherId === $this->id) continue;
            $inserts[] = [
                'asset_id_1' => $this->id,
                'asset_id_2' => $otherId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $inserts[] = [
                'asset_id_1' => $otherId,
                'asset_id_2' => $this->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($inserts)) {
            \Illuminate\Support\Facades\DB::table('asset_conflicts')->insertOrIgnore($inserts);
        }
    }
}
