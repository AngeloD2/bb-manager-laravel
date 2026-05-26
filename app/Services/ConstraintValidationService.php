<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaFolder;

/**
 * ConstraintValidationService
 *
 * Mirrors the TypeScript ConstraintValidationService in the Expo app, but
 * performs live DB queries instead of filtering in-memory arrays.
 * Called by DeviceSyncService and TokenManagerService.
 */
class ConstraintValidationService
{
    public const VALID                  = 'valid';
    public const NO_TOKENS              = 'no_tokens';
    public const HOURLY_EXCEEDED        = 'hourly_exceeded';
    public const DAILY_EXCEEDED         = 'daily_exceeded';
    public const FOLDER_DAILY_EXCEEDED  = 'folder_daily_exceeded';

    /**
     * Validate whether an asset may be scheduled for the next play slot.
     *
     * @return string  One of the class constants above.
     */
    public function validate(MediaAsset $asset): string
    {
        // 1. Token economy gate
        if ($asset->play_tokens_remaining <= 0) {
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

        // 4. Macro: folder daily token cap
        if ($asset->folder_id !== null) {
            $folder = $asset->folder ?? MediaFolder::find($asset->folder_id);
            if ($folder?->isDailyCapped()) {
                return self::FOLDER_DAILY_EXCEEDED;
            }
        }

        return self::VALID;
    }

    /** Convenience: returns true only when fully eligible. */
    public function isEligible(MediaAsset $asset): bool
    {
        return $this->validate($asset) === self::VALID;
    }
}
