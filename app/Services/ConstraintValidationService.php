<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaLoop;

/**
 * ConstraintValidationService
 *
 * Mirrors the TypeScript ConstraintValidationService in the Expo app, but
 * performs live DB queries instead of filtering in-memory arrays.
 * Called by DeviceSyncService and SpotManagerService.
 */
class ConstraintValidationService
{
    public const VALID                   = 'valid';
    public const NO_TOKENS               = 'no_tokens';
    public const HOURLY_EXCEEDED         = 'hourly_exceeded';
    public const DAILY_EXCEEDED          = 'daily_exceeded';
    public const FOLDER_DAILY_EXCEEDED   = 'folder_daily_exceeded';
    public const OUTSIDE_FLIGHT_DATES    = 'outside_flight_dates';
    public const OUTSIDE_PLAYBACK_WINDOW = 'outside_playback_window';

    public const CONFLICT                = 'conflict';

    /**
     * Validate whether an asset may be scheduled for the next play spot.
     *
     * @return string  One of the class constants above.
     */
    public function validate(MediaAsset $asset, ?string $previousAssetId = null, ?\Carbon\Carbon $now = null): string
    {
        $now ??= now();

        // 0. Campaign flight period gate (skip before start or after end date)
        if (!$asset->isWithinCampaignPeriod($now)) {
            return self::OUTSIDE_FLIGHT_DATES;
        }

        // 0b. Specific playback-time window gate
        if (!$asset->isWithinPlaybackWindow($now)) {
            return self::OUTSIDE_PLAYBACK_WINDOW;
        }

        // 1. Spot economy gate
        if ($asset->play_spots_remaining <= 0) {
            return self::NO_TOKENS;
        }

        // 2. Micro: max plays per hour
        if ($asset->max_plays_per_hour !== null) {
            if ($asset->playsLastHour() >= $asset->max_plays_per_hour) {
                return self::HOURLY_EXCEEDED;
            }
        }

        // 3. Micro: max plays per day
        if ($asset->max_daily_plays !== null) {
            if ($asset->playsToday() >= $asset->max_daily_plays) {
                return self::DAILY_EXCEEDED;
            }
        }

        // 4. Macro: loop daily spot cap
        if ($asset->loop_id !== null) {
            $loop = $asset->loop ?? MediaLoop::find($asset->loop_id);
            if ($loop?->isDailyCapped()) {
                return self::FOLDER_DAILY_EXCEEDED;
            }
        }

        // 5. Asset Conflicts (Do not play back-to-back with conflicts)
        if ($previousAssetId && $asset->relationLoaded('conflicts')) {
            if ($asset->conflicts->contains('id', $previousAssetId)) {
                return self::CONFLICT;
            }
        } elseif ($previousAssetId) {
            // Fallback if not eager loaded, but we should always eager load for performance
            $conflicts = $asset->conflicts()->pluck('media_assets.id')->toArray();
            if (in_array($previousAssetId, $conflicts)) {
                return self::CONFLICT;
            }
        }

        return self::VALID;
    }

    /** Convenience: returns true only when fully eligible. */
    public function isEligible(MediaAsset $asset, ?string $previousAssetId = null): bool
    {
        return $this->validate($asset, $previousAssetId) === self::VALID;
    }
}
