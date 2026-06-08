<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QueueGenerationService
{
    public function __construct(
        private readonly ConstraintValidationService $constraintValidator
    ) {}

    /**
     * Pops the next asset from the device's generated queue.
     */
    public function popNextAsset(Device $device): ?array
    {
        $queue = $this->getUpcomingQueue($device, 12);

        // A frozen device only plays high-priority overrides (Play Next).
        // Regular schedule items are withheld until it is unfrozen.
        if ($device->is_frozen) {
            $overrideIndex = null;
            foreach ($queue as $i => $item) {
                if (!empty($item['is_override'])) {
                    $overrideIndex = $i;
                    break;
                }
            }

            if ($overrideIndex === null) {
                return null;
            }

            $nextItem = $queue[$overrideIndex];
            array_splice($queue, $overrideIndex, 1);
            $this->saveQueue($device, $queue);

            return $nextItem;
        }

        if (empty($queue)) {
            return null;
        }

        $nextItem = array_shift($queue);
        $this->saveQueue($device, $queue);

        return $nextItem;
    }

    /**
     * Gets the upcoming queue, generating new items if needed to fill the requested size.
     */
    public function getUpcomingQueue(Device $device, int $targetSize = 12): array
    {
        $cacheKey = "device:{$device->id}:queue";
        $queue = Cache::get($cacheKey, []);

        // Slot length is global; loop caps are charged by airtime (a long clip
        // spends several slots), so loop-daily tallies add the asset's footprint.
        $secondsPerSpot = (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);

        // Running tallies of spots already scheduled in the queue we are rebuilding,
        // so per-hour / per-day / loop caps are enforced across the WHOLE visible
        // queue (retained items + freshly generated ones), not just past plays.
        $projHourly = [];    // asset_id => play count scheduled this batch
        $projDaily = [];     // asset_id => play count scheduled this batch
        $projLoopDaily = []; // loop_id  => slot footprint scheduled this batch

        $validQueue = [];
        $previousAssetId = null;
        foreach ($queue as $item) {
            if ($item['is_override']) {
                $validQueue[] = $item;
                $previousAssetId = $item['asset_id'];
                continue;
            }
            $asset = MediaAsset::with('conflicts')->find($item['asset_id']);
            if (!$asset) {
                continue;
            }
            $ph = $projHourly[$asset->id] ?? 0;
            $pd = $projDaily[$asset->id] ?? 0;
            $pl = $asset->loop_id ? ($projLoopDaily[$asset->loop_id] ?? 0) : 0;
            if ($this->constraintValidator->validate($asset, $previousAssetId, null, $ph, $pd, $pl)
                === ConstraintValidationService::VALID) {
                $validQueue[] = $item;
                $previousAssetId = $item['asset_id'];
                $projHourly[$asset->id] = $ph + 1;
                $projDaily[$asset->id] = $pd + 1;
                if ($asset->loop_id) {
                    $projLoopDaily[$asset->loop_id] = $pl + $asset->spotFootprint($secondsPerSpot);
                }
            }
        }
        $queue = $validQueue;

        $itemsToGenerate = $targetSize - count($queue);

        if ($itemsToGenerate > 0) {
            // Find what is currently playing if the queue is totally empty
            if (empty($queue)) {
                $previousAssetId = \App\Models\PlaybackLog::where('device_id', $device->id)
                    ->orderBy('played_at', 'desc')
                    ->value('asset_id');
            }

            $newItems = $this->generateNextSequence(
                $device, $itemsToGenerate, $previousAssetId,
                $projHourly, $projDaily, $projLoopDaily, $secondsPerSpot
            );
            $queue = array_merge($queue, $newItems);
            $this->saveQueue($device, $queue);
        }

        return $queue;
    }

    public function injectOverride(Device $device, MediaAsset $asset): void
    {
        $queue = $this->getUpcomingQueue($device, 12);
        
        $overrideItem = [
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'asset_name' => $asset->name,
            'duration_secs' => $asset->duration_secs,
            'file_type' => $asset->file_type,
            'is_override' => true,
            'loop_id' => $asset->loop_id,
        ];

        array_unshift($queue, $overrideItem);
        $this->saveQueue($device, $queue);
    }

    private function saveQueue(Device $device, array $queue): void
    {
        $cacheKey = "device:{$device->id}:queue";
        Cache::put($cacheKey, $queue, now()->addDays(1));
    }

    private function generateNextSequence(
        Device $device,
        int $count,
        ?string $previousAssetId = null,
        array $projHourly = [],
        array $projDaily = [],
        array $projLoopDaily = [],
        int $secondsPerSpot = 15
    ): array {
        $primaryLoops = MediaLoop::where('is_fallback', false)
            ->with(['assets' => function($q) {
                $q->where('is_synced', true)
                  ->orderBy('order_index', 'asc')
                  ->with('conflicts');
            }])
            ->orderBy('created_at', 'asc')
            ->get();
            
        $masterPrimaryAssets = new Collection();
        foreach ($primaryLoops as $loop) {
            foreach ($loop->assets as $asset) {
                if ($this->isAssignedToDevice($asset, $device)) {
                    $masterPrimaryAssets->push($asset);
                }
            }
        }
        
        $fallbackLoops = MediaLoop::where('is_fallback', true)
            ->with(['assets' => function($q) {
                $q->where('is_synced', true)
                  ->orderBy('order_index', 'asc')
                  ->with('conflicts');
            }])
            ->orderBy('created_at', 'asc')
            ->get();
            
        $masterFallbackAssets = new Collection();
        foreach ($fallbackLoops as $loop) {
            foreach ($loop->assets as $asset) {
                if ($this->isAssignedToDevice($asset, $device)) {
                    $masterFallbackAssets->push($asset);
                }
            }
        }

        $generated = [];
        
        $currentIndex = -1;
        if ($previousAssetId) {
            $currentIndex = $masterPrimaryAssets->search(fn($a) => $a->id === $previousAssetId);
            if ($currentIndex === false) {
                $currentIndex = -1;
            }
        }
        
        $fallbackIndex = -1;
        if ($previousAssetId && $currentIndex === -1) {
            $fallbackIndex = $masterFallbackAssets->search(fn($a) => $a->id === $previousAssetId);
            if ($fallbackIndex === false) {
                $fallbackIndex = -1;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $selected = null;
            $attempts = 0;
            
            if ($masterPrimaryAssets->isNotEmpty()) {
                while ($attempts < $masterPrimaryAssets->count()) {
                    $currentIndex = ($currentIndex + 1) % $masterPrimaryAssets->count();
                    $candidate = $masterPrimaryAssets[$currentIndex];
                    if ($this->isEligibleProjected($candidate, $previousAssetId, $projHourly, $projDaily, $projLoopDaily)) {
                        $selected = $candidate;
                        break;
                    }
                    $attempts++;
                }
            }

            if (!$selected && $masterFallbackAssets->isNotEmpty()) {
                $attempts = 0;
                while ($attempts < $masterFallbackAssets->count()) {
                    $fallbackIndex = ($fallbackIndex + 1) % $masterFallbackAssets->count();
                    $candidate = $masterFallbackAssets[$fallbackIndex];
                    if ($this->isEligibleProjected($candidate, $previousAssetId, $projHourly, $projDaily, $projLoopDaily)) {
                        $selected = $candidate;
                        break;
                    }
                    $attempts++;
                }
            }

            if (!$selected) {
                $anyWithSpots = MediaAsset::where('play_spots_remaining', '>', 0)
                    ->get()
                    ->filter(fn($a) => $this->isAssignedToDevice($a, $device));
                if ($anyWithSpots->isNotEmpty()) {
                    $selected = $anyWithSpots->random();
                }
            }

            if ($selected) {
                $previousAssetId = $selected->id;
                // Tally this scheduled spot so the next iteration sees the consumed
                // hourly/daily/loop budget and yields to the fallback once capped.
                $projHourly[$selected->id] = ($projHourly[$selected->id] ?? 0) + 1;
                $projDaily[$selected->id] = ($projDaily[$selected->id] ?? 0) + 1;
                if ($selected->loop_id) {
                    $projLoopDaily[$selected->loop_id] = ($projLoopDaily[$selected->loop_id] ?? 0)
                        + $selected->spotFootprint($secondsPerSpot);
                }
                $generated[] = [
                    'id' => (string) Str::uuid(),
                    'asset_id' => $selected->id,
                    'asset_name' => $selected->name,
                    'duration_secs' => $selected->duration_secs,
                    'file_type' => $selected->file_type,
                    'is_override' => false,
                    'loop_id' => $selected->loop_id,
                ];
            }
        }

        return $generated;
    }

    /**
     * Eligibility check that folds in spots already scheduled earlier in the same
     * batch (keyed by asset id and loop id), so per-hour/day/loop caps are honored
     * as the queue is generated rather than only against persisted playback logs.
     */
    private function isEligibleProjected(
        MediaAsset $asset,
        ?string $previousAssetId,
        array $projHourly,
        array $projDaily,
        array $projLoopDaily
    ): bool {
        $ph = $projHourly[$asset->id] ?? 0;
        $pd = $projDaily[$asset->id] ?? 0;
        $pl = $asset->loop_id ? ($projLoopDaily[$asset->loop_id] ?? 0) : 0;

        return $this->constraintValidator->validate($asset, $previousAssetId, null, $ph, $pd, $pl)
            === ConstraintValidationService::VALID;
    }

    private function isAssignedToDevice(MediaAsset $asset, Device $device): bool
    {
        // Asset-level: global assets are visible to all devices
        if ($asset->is_global) {
            return true;
        }
        // Asset-level: per-billboard check
        if (!empty($asset->assigned_devices) && !in_array($device->id, $asset->assigned_devices)) {
            return false;
        }
        if (empty($asset->assigned_devices)) {
            // Inherit from loop
            if ($asset->loop && $asset->loop->is_global) {
                return true;
            }
            if ($asset->loop && !empty($asset->loop->assigned_devices)) {
                return in_array($device->id, $asset->loop->assigned_devices);
            }
            // No assignment at all — not visible
            return false;
        }
        return true;
    }
}
