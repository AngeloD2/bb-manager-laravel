<?php

namespace App\Services;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\Setting;
use App\Models\TimelineOverride;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * BillboardSyncService
 *
 * Assembles the complete sync payload that a billboard receives on
 * GET /api/v1/sync.  It strips out spot-exhausted and constraint-blocked
 * assets so the billboard only sees what it is actually eligible to play,
 * then appends any pending override commands.
 */
class BillboardSyncService
{
    public function __construct(
        private readonly ConstraintValidationService $constraintValidator
    ) {}

    /**
     * Build and return the full sync payload for a given billboard.
     *
     * @return array{
     *   billboard: array,
     *   loops: Collection,
     *   eligible_assets: Collection,
     *   fallback_assets: Collection,
     *   pending_overrides: Collection,
     * }
     */
    public function buildPayload(Billboard $billboard): array
    {
        // Mark the billboard as online
        $billboard->heartbeat();

        // ── Loops ──────────────────────────────────────────────────────────
        $loops = MediaLoop::withCount('assets')
            ->get()
            ->filter(function (MediaLoop $loop) use ($billboard) {
                // Global loops are visible to all billboards
                if ($loop->is_global) {
                    return true;
                }
                // Per-billboard: must be explicitly assigned
                if (empty($loop->assigned_billboards)) {
                    return false;
                }
                return in_array($billboard->id, $loop->assigned_billboards);
            })
            ->values();

        $isAssignedToBillboard = function (MediaAsset $asset) use ($billboard) {
            // Asset-level: global assets are visible to all billboards
            if ($asset->is_global) {
                return true;
            }
            
            // 1. Explicit assignment takes precedence
            if (!empty($asset->assigned_billboards)) {
                return in_array($billboard->id, $asset->assigned_billboards);
            }
            
            // 2. Zone targeting decides eligibility if set
            if (!empty($asset->targeted_zones)) {
                return $billboard->zone_id && in_array($billboard->zone_id, $asset->targeted_zones);
            }

            // 3. Fall back to loop assignment logic
            if ($asset->loop && $asset->loop->is_global) {
                return true;
            }
            if ($asset->loop && !empty($asset->loop->assigned_billboards)) {
                return in_array($billboard->id, $asset->loop->assigned_billboards);
            }
            
            return false;
        };

        // ── Assets: primary (non-fallback) ───────────────────────────────────
        $primaryAssets = $billboard->is_frozen ? collect() : MediaAsset::with('loop.campaign', 'conflicts')
            ->where('is_synced', true)
            ->whereHas('loop', fn ($q) => $q->where('is_fallback', false))
            ->get()
            ->filter(fn (MediaAsset $asset) => $this->constraintValidator->isEligible($asset, [], $billboard->timezone))
            ->filter($isAssignedToBillboard)
            ->values();

        // ── Assets: fallback ─────────────────────────────────────────────────
        $fallbackAssets = $billboard->is_frozen ? collect() : MediaAsset::with('loop.campaign', 'conflicts')
            ->where('is_synced', true)
            ->whereHas('loop', fn ($q) => $q->where('is_fallback', true))
            ->get()
            ->filter($isAssignedToBillboard)
            ->values();

        // ── Assets: standalone (no loop) ─────────────────────────────────────
        $standaloneAssets = $billboard->is_frozen ? collect() : MediaAsset::with('loop.campaign', 'conflicts')
            ->where('is_synced', true)
            ->whereNull('loop_id')
            ->get()
            ->filter($isAssignedToBillboard)
            ->values();

        // ── Pending overrides for this specific billboard ────────────────────────
        // Only deliver an override once its asset has finished processing
        // (is_synced=true, final file_path on storage). Overrides are consumed
        // on delivery, so handing one out while the asset is still transcoding
        // would burn it against an in-flux object and lose the "Play Next". Such
        // overrides stay unconsumed and ride the next /sync — which AssetProcessingJob
        // triggers the moment processing completes.
        $pendingOverrides = $billboard->pendingOverrides()
            ->with('asset')
            ->get()
            ->filter(fn (TimelineOverride $o) => $o->asset && $o->asset->is_synced)
            ->values();

        // Mark overrides as consumed so they are not re-delivered
        $pendingOverrides->each(fn (TimelineOverride $o) => $o->consume());

        return [
            'billboard'           => $billboard,
            'loops'          => $loops,
            'eligible_assets'  => $primaryAssets,
            'fallback_assets'  => $fallbackAssets,
            'standalone_assets'=> $standaloneAssets,
            'pending_overrides'=> $pendingOverrides,
            // Pre-baked ordering + counter snapshot so the billboard can sequence
            // and meter spots locally (and entirely offline) between syncs.
            'schedule'         => $this->buildSchedule($billboard, $primaryAssets, $fallbackAssets),
            'quota'            => $this->buildQuota($billboard, $primaryAssets, $fallbackAssets, $standaloneAssets, $loops),
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
     * Order the eligible assets the way the billboard should play them round-robin.
     * Primary assets are sequenced by their loop's position in the billboard's
     * user-defined `loop_orders`, then by each asset's `order_index`. Fallbacks
     * follow their own order and are only reached when no primary qualifies.
     *
     * @return array{primary: array<int, array>, fallback: array<int, array>}
     */
    private function buildSchedule(Billboard $billboard, Collection $primaryAssets, Collection $fallbackAssets): array
    {
        $loopOrder = collect($billboard->loop_orders ?? [])->flip(); // loop_id => position

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
     * Snapshot of every counter the billboard decrements locally between syncs, plus
     * the timestamps it needs to roll hourly/daily windows offline. The server
     * remains the billing authority; this is only the starting point the billboard
     * meters against until the next reconciling sync.
     */
    private function buildQuota(Billboard $billboard, Collection $primaryAssets, Collection $fallbackAssets, Collection $standaloneAssets, Collection $loops): array
    {
        $secondsPerSpot = $this->secondsPerSpot();

        $assets = [];
        foreach ($primaryAssets->merge($fallbackAssets)->merge($standaloneAssets) as $asset) {
            /** @var MediaAsset $asset */
            // Resolve the window once: it reaches through loop -> campaign, so
            // calling it per-field would be a second lazy load per asset.
            [$flightFrom, $flightUntil] = $asset->effectiveFlightWindow();

            $assets[$asset->id] = [
                'play_spots_remaining' => (int) $asset->play_spots_remaining,
                'footprint'            => $asset->spotFootprint($secondsPerSpot),
                'max_plays_per_hour'   => $asset->max_plays_per_hour,
                'plays_last_hour'      => $asset->playsLastHour(),
                'last_played_at'       => $asset->lastPlayedAt(),
                'max_daily_plays'      => $asset->max_daily_plays,
                'plays_today'          => $asset->playsToday($billboard->timezone),
                // The board filters on these locally so it stays correct
                // offline, and knows nothing about Campaigns. Send the
                // effective window — campaign narrowed by the asset's own
                // override — under the keys it already reads.
                'campaign_start_date'  => $flightFrom?->format('Y-m-d'),
                'campaign_end_date'    => $flightUntil?->format('Y-m-d'),
                'playback_times'       => $asset->playback_times ?? [],
                'conflicts'            => $asset->relationLoaded('conflicts')
                    ? $asset->conflicts->map(fn($c) => ['id' => $c->id, 'slots' => (int) $c->pivot->separation_slots])->all()
                    : $asset->conflicts()->get()->map(fn($c) => ['id' => $c->id, 'slots' => (int) $c->pivot->separation_slots])->all(),
            ];
        }

        $loopQuota = [];
        foreach ($loops as $loop) {
            /** @var MediaLoop $loop */
            $loopQuota[$loop->id] = [
                'max_daily_spots'   => $loop->max_daily_spots,
                'spots_spent_today' => $loop->spotsSpentToday($billboard->timezone),
            ];
        }

        return [
            'as_of'            => now()->toIso8601String(),
            'seconds_per_spot' => $secondsPerSpot,
            'billboard'           => $this->billboardSpotState($billboard),
            'assets'           => $assets,
            'loops'            => $loopQuota,
        ];
    }

    /**
     * Board-level inventory for today's active window. Mirrors the admin
     * dashboard math in BillboardController so the billboard and dashboard agree on
     * total/played/open spots.
     *
     * @return array{active_hours_start: ?string, active_hours_end: ?string, total_spots: int, played_spots: int, open_spots: int}
     */
    public function billboardSpotState(Billboard $billboard): array
    {
        $secondsPerSpot = $this->secondsPerSpot();
        $totalSpots = 0;
        $playedSpots = 0;

        if ($billboard->active_hours_start && $billboard->active_hours_end) {
            $tz  = $billboard->timezone ?? 'UTC';
            $now = now($tz);
            $start = Carbon::parse($now->format('Y-m-d') . ' ' . Carbon::parse($billboard->active_hours_start)->format('H:i:s'), $tz);
            $end   = Carbon::parse($now->format('Y-m-d') . ' ' . Carbon::parse($billboard->active_hours_end)->format('H:i:s'), $tz);
            if ($end->lessThan($start)) {
                $end->addDay();
            }

            $totalSpots  = (int) floor($start->diffInSeconds($end) / $secondsPerSpot);
            $playedSpots = (int) $billboard->playbackLogs()->whereBetween('played_at', [$start, $end])->sum('spot_spent');
        }

        return [
            'active_hours_start' => $billboard->active_hours_start,
            'active_hours_end'   => $billboard->active_hours_end,
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
