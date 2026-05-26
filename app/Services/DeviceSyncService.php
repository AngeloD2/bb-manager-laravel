<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\TimelineOverride;
use Illuminate\Support\Collection;

/**
 * DeviceSyncService
 *
 * Assembles the complete sync payload that a billboard device receives on
 * GET /api/v1/sync.  It strips out token-exhausted and constraint-blocked
 * assets so the device only sees what it is actually eligible to play,
 * then appends any pending override commands.
 */
class DeviceSyncService
{
    public function __construct(
        private readonly ConstraintValidationService $constraintValidator
    ) {}

    /**
     * Build and return the full sync payload for a given device.
     *
     * @return array{
     *   device: array,
     *   folders: Collection,
     *   eligible_assets: Collection,
     *   fallback_assets: Collection,
     *   pending_overrides: Collection,
     * }
     */
    public function buildPayload(Device $device): array
    {
        // Mark the device as online
        $device->heartbeat();

        // ── Folders ──────────────────────────────────────────────────────────
        $folders = MediaFolder::withCount('assets')->get();

        // ── Assets: primary (non-fallback) ───────────────────────────────────
        $primaryAssets = MediaAsset::with('folder')
            ->where('is_synced', true)
            ->whereHas('folder', fn ($q) => $q->where('is_fallback', false))
            ->get()
            ->filter(fn (MediaAsset $asset) => $this->constraintValidator->isEligible($asset))
            ->values();

        // ── Assets: fallback ─────────────────────────────────────────────────
        $fallbackAssets = MediaAsset::with('folder')
            ->where('is_synced', true)
            ->whereHas('folder', fn ($q) => $q->where('is_fallback', true))
            ->get();

        // ── Pending overrides for this specific device ────────────────────────
        $pendingOverrides = $device->pendingOverrides()
            ->with('asset')
            ->get();

        // Mark overrides as consumed so they are not re-delivered
        $pendingOverrides->each(fn (TimelineOverride $o) => $o->consume());

        return [
            'device'           => $device,
            'folders'          => $folders,
            'eligible_assets'  => $primaryAssets,
            'fallback_assets'  => $fallbackAssets,
            'pending_overrides'=> $pendingOverrides,
            'synced_at'        => now()->toIso8601String(),
        ];
    }

    /**
     * Return a signed S3 URL for a specific asset (edge cache refresh).
     * The URL expires in 1 hour by default.
     */
    public function assetDownloadUrl(MediaAsset $asset, int $ttlSeconds = 3600): string
    {
        return $asset->deliveryUrl($ttlSeconds);
    }
}
