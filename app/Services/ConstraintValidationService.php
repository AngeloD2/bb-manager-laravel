<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaLoop;

/**
 * ConstraintValidationService
 *
 * Mirrors the TypeScript ConstraintValidationService in the Expo app, but
 * performs live DB queries instead of filtering in-memory arrays.
 * Called by BillboardSyncService and SpotManagerService.
 */
class ConstraintValidationService
{
    public const VALID                   = 'valid';
    public const NO_SPOTS_REMAINING      = 'no_spots_remaining';
    public const HOURLY_EXCEEDED         = 'hourly_exceeded';
    public const DAILY_EXCEEDED          = 'daily_exceeded';
    public const LOOP_DAILY_EXCEEDED     = 'loop_daily_exceeded';
    public const OUTSIDE_FLIGHT_DATES    = 'outside_flight_dates';
    public const OUTSIDE_PLAYBACK_WINDOW = 'outside_playback_window';

    public const CONFLICT                = 'conflict';

    /**
     * Validate whether an asset may be scheduled for the next play spot.
     *
     * @return string  One of the class constants above.
     */
    public function validate(
        MediaAsset $asset,
        array $history = [],
        ?\Carbon\Carbon $now = null,
        int $projectedHourly = 0,
        int $projectedDaily = 0,
        int $projectedLoopDaily = 0,
        ?string $timezone = null
    ): string {
        $tz = $timezone ?? config('app.timezone', 'UTC');
        $now ??= now($tz);

        // 0. Flight window gate: the campaign's window narrowed by the asset's own
        if (!$asset->isWithinFlightWindow($now)) {
            return self::OUTSIDE_FLIGHT_DATES;
        }

        // 0b. Specific playback-time window gate
        if (!$asset->isWithinPlaybackWindow($now)) {
            return self::OUTSIDE_PLAYBACK_WINDOW;
        }

        // 1. Spot economy gate
        if ($asset->play_spots_remaining <= 0) {
            return self::NO_SPOTS_REMAINING;
        }

        // The $projected* counts represent spots already scheduled for this asset
        // (and its loop) earlier in the SAME queue-generation batch. They are added
        // to the persisted play counts so per-hour / per-day / loop caps are honored
        // as the queue is built — without them, a single batch would schedule a
        // capped asset far beyond its limit and the fallback loop would never run.

        // 2. Micro: max plays per hour
        if ($asset->max_plays_per_hour !== null) {
            if ($asset->playsLastHour() + $projectedHourly >= $asset->max_plays_per_hour) {
                return self::HOURLY_EXCEEDED;
            }
        }

        // 3. Micro: max plays per day
        if ($asset->max_daily_plays !== null) {
            if ($asset->playsToday($timezone) + $projectedDaily >= $asset->max_daily_plays) {
                return self::DAILY_EXCEEDED;
            }
        }

        // 4. Macro: loop daily spot cap
        if ($asset->loop_id !== null) {
            $loop = $asset->loop ?? MediaLoop::find($asset->loop_id);
            if ($loop && $loop->max_daily_spots !== null
                && ($loop->spotsSpentToday($timezone) + $projectedLoopDaily) >= $loop->max_daily_spots) {
                return self::LOOP_DAILY_EXCEEDED;
            }
        }

        // 5. Asset Conflicts (Check against history array up to separation_slots)
        if (!empty($history)) {
            $conflicts = $asset->relationLoaded('conflicts') 
                ? $asset->conflicts 
                : $asset->conflicts()->get();

            foreach ($conflicts as $conflict) {
                $slots = max(1, $conflict->pivot->separation_slots ?? 1);
                // Check if the conflict is in the last $slots positions of history
                $recentHistory = array_slice($history, -$slots);
                if (in_array($conflict->id, $recentHistory, true)) {
                    return self::CONFLICT;
                }
            }
        }

        return self::VALID;
    }

    /** Convenience: returns true only when fully eligible. */
    public function isEligible(MediaAsset $asset, array $history = [], ?string $timezone = null): bool
    {
        return $this->validate($asset, $history, null, 0, 0, 0, $timezone) === self::VALID;
    }
}
