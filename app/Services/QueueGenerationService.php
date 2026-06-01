<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
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

        $validQueue = [];
        $previousAssetId = null;
        foreach ($queue as $item) {
            if ($item['is_override']) {
                $validQueue[] = $item;
                $previousAssetId = $item['asset_id'];
                continue;
            }
            $asset = MediaAsset::with('conflicts')->find($item['asset_id']);
            if ($asset && $this->constraintValidator->isEligible($asset, $previousAssetId)) {
                $validQueue[] = $item;
                $previousAssetId = $item['asset_id'];
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

            $newItems = $this->generateNextSequence($device, $itemsToGenerate, $previousAssetId);
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

    private function generateNextSequence(Device $device, int $count, ?string $previousAssetId = null): array
    {
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
                    if ($this->constraintValidator->isEligible($candidate, $previousAssetId)) {
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
                    if ($this->constraintValidator->isEligible($candidate, $previousAssetId)) {
                        $selected = $candidate;
                        break;
                    }
                    $attempts++;
                }
            }

            if (!$selected) {
                $anyWithSpots = MediaAsset::where('play_spots_remaining', '>', 0)->get();
                if ($anyWithSpots->isNotEmpty()) {
                    $selected = $anyWithSpots->random();
                }
            }

            if ($selected) {
                $previousAssetId = $selected->id;
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
