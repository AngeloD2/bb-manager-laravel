<?php

namespace App\Services;

use App\Events\DeviceCommand;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * DeviceNotifier
 *
 * Pushes a real-time "sync" command to billboard devices when the schedule
 * or media library changes, so connected players re-pull /sync and regenerate
 * their playback queue instead of waiting for the next poll cycle.
 */
class DeviceNotifier
{
    /**
     * Notify every device that the schedule/media changed.
     * Clears each device's cached queue so it regenerates with the new content.
     */
    public function notifyScheduleChanged(): void
    {
        Device::query()->each(fn (Device $device) => $this->notifyDevice($device));
    }

    /**
     * Notify a single device to re-sync.
     */
    public function notifyDevice(Device $device): void
    {
        Cache::forget("device:{$device->id}:queue");

        if (config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new DeviceCommand($device, 'sync'));
            } catch (\Throwable) {
                // WebSocket broadcast failed — polling fallback will handle it.
            }
        }
    }
}
