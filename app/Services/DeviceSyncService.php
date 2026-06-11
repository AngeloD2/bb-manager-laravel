<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\Setting;
use App\Models\TimelineOverride;
use Carbon\Carbon;
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
        $primaryAssets = $device->is_frozen ? collect() : MediaAsset::with('loop', 'conflicts')
            ->where('is_synced', true)
            ->whereHas('loop', fn ($q) => $q->where('is_fallback', false))
            ->get()
            ->filter(fn (MediaAsset $asset) => $this->constraintValidator->isEligible($asset, null, $device->timezone))
            ->filter($isAssignedToDevice)
            ->values();

        // ── Assets: fallback ─────────────────────────────────────────────────
        $fallbackAssets = $device->is_frozen ? collect() : MediaAsset::with('loop', 'conflicts')
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
            // Pre-baked ordering + counter snapshot so the device can sequence
            // and meter spots locally (and entirely offline) between syncs.
            'schedule'         => $this->buildSchedule($device, $primaryAssets, $fallbackAssets),
            'quota'            => $this->buildQuota($device, $primaryAssets, $fallbackAssets, $loops),
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
     * Order the eligible assets the way the device should play them round-robin.
     * Primary assets are sequenced by their loop's position in the device's
     * user-defined `loop_orders`, then by each asset's `order_index`. Fallbacks
     * follow their own order and are only reached when no primary qualifies.
     *
     * @return array{primary: array<int, array>, fallback: array<int, array>}
     */
    private function buildSchedule(Device $device, Collection $primaryAssets, Collection $fallbackAssets): array
    {
        $loopOrder = collect($device->loop_orders ?? [])->flip(); // loop_id => position

        $sequence = fn (Collection $assets) => $assets
            ->sortBy([
                fn (MediaAsset $a) => $loopOrder[$a->loop_id] ?? PHP_INT_MAX,
                fn (MediaAsset $a) => $a->order_index ?? PHP_INT_MAX,
            ])
            ->map(fn (MediaAsset $a) => [
                'asset_id'    => $a->id,
                'loop_id'     => $a->loop_id,
                'order_index' => $a->order_index,
            ])
            ->values()
            ->all();

        return [
            'primary'  => $sequence($primaryAssets),
            'fallback' => $sequence($fallbackAssets),
        ];
    }

    /**
     * Snapshot of every counter the device decrements locally between syncs, plus
     * the timestamps it needs to roll hourly/daily windows offline. The server
     * remains the billing authority; this is only the starting point the device
     * meters against until the next reconciling sync.
     */
    private function buildQuota(Device $device, Collection $primaryAssets, Collection $fallbackAssets, Collection $loops): array
    {
        $secondsPerSpot = $this->secondsPerSpot();

        $assets = [];
        foreach ($primaryAssets->merge($fallbackAssets) as $asset) {
            /** @var MediaAsset $asset */
            $assets[$asset->id] = [
                'play_spots_remaining' => (int) $asset->play_spots_remaining,
                'footprint'            => $asset->spotFootprint($secondsPerSpot),
                'max_plays_per_hour'   => $asset->max_plays_per_hour,
                'plays_last_hour'      => $asset->playsLastHour(),
                'last_played_at'       => $asset->lastPlayedAt(),
                'max_daily_plays'      => $asset->max_daily_plays,
                'plays_today'          => $asset->playsToday($device->timezone),
                'campaign_start_date'  => $asset->campaign_start_date?->format('Y-m-d'),
                'campaign_end_date'    => $asset->campaign_end_date?->format('Y-m-d'),
                'playback_times'       => $asset->playback_times ?? [],
                'conflict_asset_ids'   => $asset->relationLoaded('conflicts')
                    ? $asset->conflicts->pluck('id')->all()
                    : $asset->conflicts()->pluck('media_assets.id')->all(),
            ];
        }

        $loopQuota = [];
        foreach ($loops as $loop) {
            /** @var MediaLoop $loop */
            $loopQuota[$loop->id] = [
                'max_daily_spots'   => $loop->max_daily_spots,
                'spots_spent_today' => $loop->spotsSpentToday($device->timezone),
            ];
        }

        return [
            'as_of'            => now()->toIso8601String(),
            'seconds_per_spot' => $secondsPerSpot,
            'device'           => $this->deviceSpotState($device),
            'assets'           => $assets,
            'loops'            => $loopQuota,
        ];
    }

    /**
     * Board-level inventory for today's active window. Mirrors the admin
     * dashboard math in DeviceController so the device and dashboard agree on
     * total/played/open spots.
     *
     * @return array{active_hours_start: ?string, active_hours_end: ?string, total_spots: int, played_spots: int, open_spots: int}
     */
    public function deviceSpotState(Device $device): array
    {
        $secondsPerSpot = $this->secondsPerSpot();
        $totalSpots = 0;
        $playedSpots = 0;

        if ($device->active_hours_start && $device->active_hours_end) {
            $tz  = $device->timezone ?? 'UTC';
            $now = now($tz);
            $start = Carbon::parse($now->format('Y-m-d') . ' ' . Carbon::parse($device->active_hours_start)->format('H:i:s'), $tz);
            $end   = Carbon::parse($now->format('Y-m-d') . ' ' . Carbon::parse($device->active_hours_end)->format('H:i:s'), $tz);
            if ($end->lessThan($start)) {
                $end->addDay();
            }

            $totalSpots  = (int) floor($start->diffInSeconds($end) / $secondsPerSpot);
            $playedSpots = (int) $device->playbackLogs()->whereBetween('played_at', [$start, $end])->sum('spot_spent');
        }

        return [
            'active_hours_start' => $device->active_hours_start,
            'active_hours_end'   => $device->active_hours_end,
            'total_spots'        => $totalSpots,
            'played_spots'       => $playedSpots,
            'open_spots'         => max(0, $totalSpots - $playedSpots),
        ];
    }

    /** Global slot length in seconds (default 15). */
    private function secondsPerSpot(): int
    {
        return (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);
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
