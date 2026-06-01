<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\TimelineOverride;
use Illuminate\Support\Collection;

/**
 * DeviceSyncService
 *
 * Assembles the complete sync payload that a billboard device receives on
 * GET /api/v1/sync.  It strips out spot-exhausted and constraint-blocked
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
     *   loops: Collection,
     *   eligible_assets: Collection,
     *   fallback_assets: Collection,
     *   pending_overrides: Collection,
     * }
     */
    public function buildPayload(Device $device): array
    {
        // Mark the device as online
        $device->heartbeat();

        // ── Loops ──────────────────────────────────────────────────────────
        $loops = MediaLoop::withCount('assets')
            ->get()
            ->filter(function (MediaLoop $loop) use ($device) {
                // Global loops are visible to all devices
                if ($loop->is_global) {
                    return true;
                }
                // Per-billboard: must be explicitly assigned
                if (empty($loop->assigned_devices)) {
                    return false;
                }
                return in_array($device->id, $loop->assigned_devices);
            })
            ->values();

        $isAssignedToDevice = function (MediaAsset $asset) use ($device) {
            // Asset-level: global assets are visible to all devices
            if ($asset->is_global) {
                return true;
            }
            // Asset-level: per-billboard check
            if (!empty($asset->assigned_devices) && !in_array($device->id, $asset->assigned_devices)) {
                return false;
            }
            if (empty($asset->assigned_devices) && !($asset->loop && $asset->loop->is_global)) {
                // Not explicitly assigned and loop is not global — check loop assignment
                if ($asset->loop && !empty($asset->loop->assigned_devices) && !in_array($device->id, $asset->loop->assigned_devices)) {
                    return false;
                }
                // Not assigned to any device and loop has no assignments — not visible
                if ($asset->loop && empty($asset->loop->assigned_devices) && !$asset->loop->is_global) {
                    return false;
                }
                if (!$asset->loop) {
                    return false;
                }
            }
            return true;
        };

        // ── Assets: primary (non-fallback) ───────────────────────────────────
        $primaryAssets = $device->is_frozen ? collect() : MediaAsset::with('loop')
            ->where('is_synced', true)
            ->whereHas('loop', fn ($q) => $q->where('is_fallback', false))
            ->get()
            ->filter(fn (MediaAsset $asset) => $this->constraintValidator->isEligible($asset))
            ->filter($isAssignedToDevice)
            ->values();

        // ── Assets: fallback ─────────────────────────────────────────────────
        $fallbackAssets = $device->is_frozen ? collect() : MediaAsset::with('loop')
            ->where('is_synced', true)
            ->whereHas('loop', fn ($q) => $q->where('is_fallback', true))
            ->get()
            ->filter($isAssignedToDevice)
            ->values();

        // ── Pending overrides for this specific device ────────────────────────
        $pendingOverrides = $device->pendingOverrides()
            ->with('asset')
            ->get();

        // Mark overrides as consumed so they are not re-delivered
        $pendingOverrides->each(fn (TimelineOverride $o) => $o->consume());

        return [
            'device'           => $device,
            'loops'          => $loops,
            'eligible_assets'  => $primaryAssets,
            'fallback_assets'  => $fallbackAssets,
            'pending_overrides'=> $pendingOverrides,
            'synced_at'        => now()->toIso8601String(),
            'broadcasting'     => [
                'key'    => config('broadcasting.connections.reverb.key'),
                'host'   => config('broadcasting.connections.reverb.options.host', '127.0.0.1'),
                'port'   => config('broadcasting.connections.reverb.options.port', 8080),
                'scheme' => config('broadcasting.connections.reverb.options.scheme', 'http'),
            ],
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
