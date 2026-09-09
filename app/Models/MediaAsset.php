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

    /**
     * Spot allowance an asset gets when the caller does not specify one.
     * Mirrors the media_assets.play_spots_remaining column default; anything
     * <= 0 is treated as out of spots and never queued.
     */
    public const DEFAULT_PLAY_SPOTS = 100;

    protected $table = 'media_assets';

    protected $fillable = [
        'name',
        'file_path',
        'file_type',
        'loop_id',
        'order_index',
        'size_bytes',
        'duration_secs',
        'targeted_zones',
        'is_synced',
        'is_global',
        'max_plays_per_hour',
        'max_daily_plays',
        'play_spots_remaining',
        'assigned_billboards',
        'runs_from',
        'runs_until',
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
        'assigned_billboards'      => 'array',
        'targeted_zones'         => 'array',
        'runs_from'             => 'date:Y-m-d',
        'runs_until'            => 'date:Y-m-d',
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
            ->withPivot('separation_slots')
            ->withTimestamps();
    }

    public function rejectionStats(): HasMany
    {
        return $this->hasMany(QueueRejectionStat::class, 'asset_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Generates a CloudFront-signed or S3 presigned URL for CDN delivery
     * to the physical billboard.
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
    public function playsToday(?string $timezone = null): int
    {
        $tz = $timezone ?? config('app.timezone', 'UTC');
        return $this->playbackLogs()
            ->where('played_at', '>=', now($tz)->startOfDay())
            ->count();
    }

    /**
     * Server-stamped timestamp of the most recent play, or null if never played.
     * Billboards use this to space out plays (pacing) after a cold sync, when they
     * only have the snapshot and not their own local play history yet.
     */
    public function lastPlayedAt(): ?string
    {
        $playedAt = $this->playbackLogs()->max('played_at');

        return $playedAt ? \Carbon\Carbon::parse($playedAt)->toIso8601String() : null;
    }

    /**
     * The dates this asset may actually air between: its campaign's window
     * (reached through its loop) narrowed by the asset's own optional override.
     * Either bound may be null, meaning unbounded on that side.
     *
     * @return array{0: ?\Carbon\Carbon, 1: ?\Carbon\Carbon}
     */
    public function effectiveFlightWindow(): array
    {
        $campaign = $this->loop?->campaign;

        $from = $this->latest($campaign?->starts_on, $this->runs_from);
        $until = $this->earliest($campaign?->ends_on, $this->runs_until);

        return [$from, $until];
    }

    /** True when the given date falls within the effective flight window. */
    public function isWithinFlightWindow(\Carbon\Carbon $date): bool
    {
        [$from, $until] = $this->effectiveFlightWindow();

        if ($from !== null && $date->lt($from->copy()->startOfDay())) {
            return false;
        }
        if ($until !== null && $date->gt($until->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    /** The later of two optional dates; null only when both are null. */
    private function latest(?\Carbon\Carbon $a, ?\Carbon\Carbon $b): ?\Carbon\Carbon
    {
        if ($a === null) return $b;
        if ($b === null) return $a;

        return $a->gt($b) ? $a : $b;
    }

    /** The earlier of two optional dates; null only when both are null. */
    private function earliest(?\Carbon\Carbon $a, ?\Carbon\Carbon $b): ?\Carbon\Carbon
    {
        if ($a === null) return $b;
        if ($b === null) return $a;

        return $a->lt($b) ? $a : $b;
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

    /**
     * How many board slots this asset occupies at the given spot length.
     * A 60s clip at 15s/spot fills 4 slots; still media fills 1. Used to charge
     * loop daily caps and board inventory by airtime rather than a flat 1/play.
     */
    public function spotFootprint(int $secondsPerSpot): int
    {
        if ($secondsPerSpot <= 0) {
            return 1;
        }
        return max(1, (int) ceil(((int) $this->duration_secs) / $secondsPerSpot));
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
