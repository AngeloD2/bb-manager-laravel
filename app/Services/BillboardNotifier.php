<?php

namespace App\Services;

use App\Events\BillboardCommand;
use App\Models\Billboard;
use Illuminate\Support\Facades\Cache;

/**
 * BillboardNotifier
 *
 * Pushes a real-time "sync" command to billboards when the schedule
 * or media library changes, so connected players re-pull /sync and regenerate
 * their playback queue instead of waiting for the next poll cycle.
 */
class BillboardNotifier
{
    /**
     * Notify every billboard that the schedule/media changed.
     * Clears each billboard's cached queue so it regenerates with the new content.
     */
    public function notifyScheduleChanged(): void
    {
        Billboard::query()->each(fn (Billboard $billboard) => $this->notifyBillboard($billboard));
    }

    /**
     * Notify a single billboard to re-sync.
     */
    public function notifyBillboard(Billboard $billboard): void
    {
        Cache::forget("billboard:{$billboard->id}:queue");

        if (config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new BillboardCommand($billboard, 'sync'));
            } catch (\Throwable) {
                // WebSocket broadcast failed — polling fallback will handle it.
            }
        }
    }
}
