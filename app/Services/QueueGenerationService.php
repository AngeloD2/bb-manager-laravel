<?php

namespace App\Services;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QueueGenerationService
{
    /**
     * Fraction of an asset's ideal inter-play interval that must elapse before it
     * may be scheduled again, spreading its plays across the hour. Kept numerically
     * identical to the React and Expo schedulers so all engines pace the same way.
     */
    private const PACING_FACTOR = 0.75;

    public function __construct(
        private readonly ConstraintValidationService $constraintValidator
    ) {}

    /**
     * Gets the upcoming queue, generating new items if needed to fill the requested size.
     */
    public function getUpcomingQueue(Billboard $billboard, int $targetSize = 12): array
    {
        $cacheKey = "billboard:{$billboard->id}:queue";
        $queue = Cache::get($cacheKey, []);

        // Slot length is global; loop caps are charged by airtime (a long clip
        // spends several slots), so loop-daily tallies add the asset's footprint.
        $secondsPerSpot = (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);

        // Running tallies of spots already scheduled in the queue we are rebuilding,
        // so per-hour / per-day / loop caps and spot budgets are enforced across the WHOLE visible
        // queue (retained items + freshly generated ones), not just past plays.
        $projHourly = [];    // asset_id => play count scheduled this batch
        $projDaily = [];     // asset_id => play count scheduled this batch
        $projLoopDaily = []; // loop_id  => slot footprint scheduled this batch
        $projSpots = [];     // asset_id => slot footprint scheduled this batch

        $validQueue = [];
        $history = [];

        // Pre-fetch assets to avoid N+1 queries
        $assetIds = collect($queue)->where('is_override', false)->pluck('asset_id')->unique()->all();
        $prefetchedAssets = empty($assetIds) ? collect() : MediaAsset::with('conflicts', 'loop.campaign')->whereIn('id', $assetIds)->get()->keyBy('id');

        foreach ($queue as $item) {
            if ($item['is_override']) {
                $validQueue[] = $item;
                $history[] = $item['asset_id'];
                continue;
            }
            $asset = $prefetchedAssets->get($item['asset_id']);
            if (!$asset) {
                continue;
            }
            $ph = $projHourly[$asset->id] ?? 0;
            $pd = $projDaily[$asset->id] ?? 0;
            $pl = $asset->loop_id ? ($projLoopDaily[$asset->loop_id] ?? 0) : 0;
            $ps = $projSpots[$asset->id] ?? 0;
            
            $validationResult = $this->constraintValidator->validate($asset, $history, null, $ph, $pd, $pl, $billboard->timezone, $ps);
            
            if ($validationResult === ConstraintValidationService::VALID) {
                $validQueue[] = $item;
                $history[] = $item['asset_id'];
                $footprint = $asset->spotFootprint($secondsPerSpot);
                $projHourly[$asset->id] = $ph + 1;
                $projDaily[$asset->id] = $pd + 1;
                $projSpots[$asset->id] = $ps + $footprint;
                if ($asset->loop_id) {
                    $projLoopDaily[$asset->loop_id] = $pl + $footprint;
                }
            } else {
                // If rejected in existing queue, we also tally it for issue 16 (rejection tracking)
                $this->tallyRejection($billboard->id, $asset->id, $validationResult);
            }
        }
        $queue = $validQueue;

        $itemsToGenerate = $targetSize - count($queue);

        if ($itemsToGenerate > 0) {
            // Find what is currently playing if the queue is totally empty
            if (empty($queue)) {
                $history = \App\Models\PlaybackLog::where('billboard_id', $billboard->id)
                    ->where('played_at', '>=', now()->subHour())
                    ->orderBy('played_at', 'desc')
                    ->limit(10)
                    ->pluck('asset_id')
                    ->reverse()
                    ->values()
                    ->all();
                // Rejected plays are never logged, so also resume after whatever
                // the board last reported starting.
                $lastStarted = Cache::get("billboard:{$billboard->id}:last_started");
                if ($lastStarted && end($history) !== $lastStarted) {
                    $history[] = $lastStarted;
                }
            }

            $newItems = $this->generateNextSequence(
                $billboard, $itemsToGenerate, $history,
                $projHourly, $projDaily, $projLoopDaily, $projSpots, $secondsPerSpot
            );
            $queue = array_merge($queue, $newItems);
            $this->saveQueue($billboard, $queue);
        }

        return $queue;
    }

    /**
     * Read-only look further ahead than the live queue: the live queue (topped up
     * to its usual size) followed by a projection of what would be generated after
     * it. Only the live queue is saved, so the billboard's cursor is untouched.
     */
    public function previewQueue(Billboard $billboard, int $count, int $liveSize = 12): array
    {
        $queue = $this->getUpcomingQueue($billboard, $liveSize);
        if ($count <= count($queue)) {
            return array_slice($queue, 0, $count);
        }

        $secondsPerSpot = (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);
        $assets = MediaAsset::whereIn('id', collect($queue)->pluck('asset_id')->unique()->all())
            ->get()
            ->keyBy('id');

        // Carry the live queue's consumption into the projection, as if it had
        // already played: caps, rotation cursor, and pacing continue from its end.
        $history = [];
        $projHourly = [];
        $projDaily = [];
        $projLoopDaily = [];
        $projSpots = [];
        $playMs = [];
        $clockMs = now()->getTimestampMs();
        foreach ($queue as $item) {
            $history[] = $item['asset_id'];
            $asset = $assets->get($item['asset_id']);
            if ($asset && !($item['is_override'] ?? false)) {
                $footprint = $asset->spotFootprint($secondsPerSpot);
                $projHourly[$asset->id] = ($projHourly[$asset->id] ?? 0) + 1;
                $projDaily[$asset->id] = ($projDaily[$asset->id] ?? 0) + 1;
                $projSpots[$asset->id] = ($projSpots[$asset->id] ?? 0) + $footprint;
                if ($asset->loop_id) {
                    $projLoopDaily[$asset->loop_id] = ($projLoopDaily[$asset->loop_id] ?? 0) + $footprint;
                }
                $playMs[$asset->id] = $clockMs;
            }
            $clockMs += (int) round(($item['duration_secs'] ?? 0) * 1000);
        }

        $projected = $this->generateNextSequence(
            $billboard, $count - count($queue), $history,
            $projHourly, $projDaily, $projLoopDaily, $projSpots, $secondsPerSpot,
            $playMs, $clockMs
        );

        return array_merge($queue, $projected);
    }

    public function injectOverride(Billboard $billboard, MediaAsset $asset): void
    {
        $queue = $this->getUpcomingQueue($billboard, 12);
        
        $overrideItem = [
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'asset_name' => $asset->name,
            'duration_secs' => $asset->duration_secs,
            'file_type' => $asset->file_type,
            'is_override' => true,
            'loop_id' => $asset->loop_id,
        ];

        if (count($queue) > 0) {
            $currentItem = $queue[0];
            $restOfQueue = array_values(array_filter(array_slice($queue, 1), function ($item) {
                return !($item['is_override'] ?? false);
            }));
            
            $overrideItem['scheduled_time'] = ($currentItem['scheduled_time'] ?? now()->getTimestampMs()) + (($currentItem['duration_secs'] ?? 15) * 1000);
            $queue = array_merge([$currentItem, $overrideItem], $restOfQueue);
            
            // Recompute scheduled times for the rest of the queue
            $t = $overrideItem['scheduled_time'] + ($overrideItem['duration_secs'] * 1000);
            for ($i = 2; $i < count($queue); $i++) {
                $queue[$i]['scheduled_time'] = $t;
                $t += ($queue[$i]['duration_secs'] * 1000);
            }
        } else {
            $overrideItem['scheduled_time'] = now()->getTimestampMs();
            $queue[] = $overrideItem;
        }

        $this->saveQueue($billboard, $queue);
    }

    public function cancelOverride(Billboard $billboard): void
    {
        $cacheKey = "billboard:{$billboard->id}:queue";
        $queue = Cache::get($cacheKey, []);

        if (empty($queue)) {
            return;
        }

        $updatedQueue = array_values(array_filter($queue, function ($item) {
            return !($item['is_override'] ?? false);
        }));

        $this->saveQueue($billboard, $updatedQueue);
    }

    private function saveQueue(Billboard $billboard, array $queue): void
    {
        $cacheKey = "billboard:{$billboard->id}:queue";
        Cache::put($cacheKey, $queue, now()->addDays(1));
    }

    /**
     * Re-anchor the live queue on the asset a billboard just started. Items up to
     * and including it have played. If it isn't queued at all — the board played
     * something the queue didn't predict — the queue is dropped so it regenerates
     * from that asset onward; otherwise it would never advance.
     */
    public function alignToStarted(Billboard $billboard, string $assetId): void
    {
        Cache::put("billboard:{$billboard->id}:last_started", $assetId, now()->addDay());

        $queue = Cache::get("billboard:{$billboard->id}:queue", []);
        foreach ($queue as $index => $item) {
            if ($item['asset_id'] !== $assetId) {
                continue;
            }
            if ($item['is_override'] ?? false) {
                // Overrides preempt the queue without advancing the primary cursor.
                array_splice($queue, $index, 1);
            } else {
                // A normal play means anything queued before it was skipped.
                array_splice($queue, 0, $index + 1);
            }
            $this->saveQueue($billboard, $queue);
            return;
        }

        Cache::forget("billboard:{$billboard->id}:queue");
    }

    private function generateNextSequence(
        Billboard $billboard,
        int $count,
        array $history = [],
        array $projHourly = [],
        array $projDaily = [],
        array $projLoopDaily = [],
        array $projSpots = [],
        int $secondsPerSpot = 15,
        array $queuedPlayMs = [],
        ?int $startMs = null
    ): array {
        $loopOrder = collect($billboard->loop_orders ?? [])->flip(); // loop_id => position
        $byLoopOrder = fn (Collection $loops) => $loops->sort(function ($a, $b) use ($loopOrder) {
            $posA = $loopOrder[$a->id] ?? PHP_INT_MAX;
            $posB = $loopOrder[$b->id] ?? PHP_INT_MAX;
            if ($posA !== $posB) {
                return $posA <=> $posB;
            }
            $cA = $a->created_at?->timestamp ?? 0;
            $cB = $b->created_at?->timestamp ?? 0;
            return $cA <=> $cB;
        })->values();

        $primaryLoops = $byLoopOrder(MediaLoop::where('is_fallback', false)
            ->with(['assets' => function($q) {
                $q->where('is_synced', true)
                  ->with('conflicts');
            }, 'campaign'])
            ->get())
            ->filter(function (MediaLoop $loop) use ($billboard) {
                return $loop->assets->contains(fn ($asset) => $this->isAssignedToBillboard($asset, $billboard));
            })->values();

        $allFallbackLoops = $byLoopOrder(MediaLoop::where('is_fallback', true)
            ->with(['assets' => function($q) {
                $q->where('is_synced', true)
                  ->with('conflicts');
            }, 'campaign'])
            ->get())
            ->filter(function (MediaLoop $loop) use ($billboard) {
                return $loop->assets->contains(fn ($asset) => $this->isAssignedToBillboard($asset, $billboard));
            })->values();

        $campaignFallbackLoops = $allFallbackLoops
            ->filter(fn (MediaLoop $l) => !empty($l->campaign_id))
            ->groupBy('campaign_id');

        $globalFallbackLoops = $allFallbackLoops
            ->filter(fn (MediaLoop $l) => empty($l->campaign_id))
            ->values();

        // Pacing state: seed each asset's last real play, then advance a virtual
        // clock by each scheduled clip's duration so later items in this batch are
        // spaced out too.
        $allAssetIds = $primaryLoops->flatMap->assets->pluck('id')
            ->merge($allFallbackLoops->flatMap->assets->pluck('id'))
            ->unique()
            ->all();

        $lastPlayedMs = empty($allAssetIds) ? [] : \App\Models\PlaybackLog::whereIn('asset_id', $allAssetIds)
            ->selectRaw('asset_id, MAX(played_at) as last_played')
            ->groupBy('asset_id')
            ->pluck('last_played', 'asset_id')
            ->map(fn ($t) => \Carbon\Carbon::parse($t)->getTimestampMs())
            ->all();
        // Plays already queued ahead of this batch (when projecting past the live queue).
        foreach ($queuedPlayMs as $assetId => $ms) {
            $lastPlayedMs[$assetId] = max($lastPlayedMs[$assetId] ?? 0, $ms);
        }
        $virtualMs = $startMs ?? now()->getTimestampMs();

        $buildPassList = function (MediaLoop $loop) use ($billboard, &$lastPlayedMs) {
            $assets = $loop->assets->filter(fn ($a) => $this->isAssignedToBillboard($a, $billboard));

            $explicit = $assets->filter(fn ($a) => $a->order_index !== null)
                ->sort(function ($a, $b) {
                    if ($a->order_index !== $b->order_index) {
                        return $a->order_index <=> $b->order_index;
                    }
                    $cA = $a->created_at?->timestamp ?? 0;
                    $cB = $b->created_at?->timestamp ?? 0;
                    if ($cA !== $cB) {
                        return $cA <=> $cB;
                    }
                    return strcmp($a->id, $b->id);
                })
                ->values();

            $unordered = $assets->filter(fn ($a) => $a->order_index === null)
                ->sort(function ($a, $b) use (&$lastPlayedMs) {
                    $tA = $lastPlayedMs[$a->id] ?? 0;
                    $tB = $lastPlayedMs[$b->id] ?? 0;
                    if ($tA !== $tB) {
                        return $tA <=> $tB;
                    }
                    $cA = $a->created_at?->timestamp ?? 0;
                    $cB = $b->created_at?->timestamp ?? 0;
                    if ($cA !== $cB) {
                        return $cA <=> $cB;
                    }
                    return strcmp($a->id, $b->id);
                })
                ->values();

            return $explicit->concat($unordered)->values();
        };

        $loopIdx = 0;
        $assetIdx = 0;
        $globalFallbackLoopIndex = 0;
        $campaignFallbackCursors = []; // campaign_id => cursor index
        $campaignFallbackAssetCursors = []; // loop_id => asset cursor index
        $globalFallbackAssetCursors = []; // loop_id => asset cursor index
        $activeLoopPassList = null;
        $activeLoopId = null;

        $previousAssetId = !empty($history) ? end($history) : null;
        if ($previousAssetId && $primaryLoops->isNotEmpty()) {
            foreach ($primaryLoops as $lIndex => $l) {
                $passList = $buildPassList($l);
                $foundPos = $passList->search(fn ($a) => $a->id === $previousAssetId);
                if ($foundPos !== false) {
                    if ($foundPos + 1 >= $passList->count()) {
                        $loopIdx = ($lIndex + 1) % $primaryLoops->count();
                        $assetIdx = 0;
                    } else {
                        $loopIdx = $lIndex;
                        $assetIdx = $foundPos + 1;
                        $activeLoopPassList = $passList;
                        $activeLoopId = $l->id;
                    }
                    break;
                }
            }
        }

        if ($previousAssetId && $globalFallbackLoops->isNotEmpty()) {
            foreach ($globalFallbackLoops as $fbIndex => $fbLoop) {
                $fbPassList = $buildPassList($fbLoop);
                $foundPos = $fbPassList->search(fn ($a) => $a->id === $previousAssetId);
                if ($foundPos !== false) {
                    $globalFallbackLoopIndex = ($fbIndex + 1) % $globalFallbackLoops->count();
                    break;
                }
            }
        }

        $generated = [];

        for ($i = 0; $i < $count; $i++) {
            $selected = null;

            if ($primaryLoops->isNotEmpty()) {
                $loopsChecked = 0;
                while ($loopsChecked < $primaryLoops->count() && !$selected) {
                    $loop = $primaryLoops[$loopIdx];

                    // Use cached pass list if we are in the middle of this loop, else build a fresh one
                    if ($activeLoopId === $loop->id && $activeLoopPassList !== null) {
                        $passList = $activeLoopPassList;
                    } else {
                        $passList = $buildPassList($loop);
                        $activeLoopPassList = $passList;
                        $activeLoopId = $loop->id;
                    }

                    if ($loop->is_bundle) {
                        if ($passList->isNotEmpty()) {
                            // Atomic bundle: first asset governs whole bundle for this pass
                            $firstAsset = $passList->first();
                            $firstVal = $this->isEligibleProjected($firstAsset, $history, $projHourly, $projDaily, $projLoopDaily, $projSpots, $billboard->timezone);
                            $firstDue = $this->isDue($firstAsset, $virtualMs, $lastPlayedMs);

                            if ($firstVal !== ConstraintValidationService::VALID || !$firstDue) {
                                if ($firstVal !== ConstraintValidationService::VALID) {
                                    $this->tallyRejection($billboard->id, $firstAsset->id, $firstVal);
                                }
                                // Skip entire bundle loop
                                $loopIdx = ($loopIdx + 1) % $primaryLoops->count();
                                $assetIdx = 0;
                                $activeLoopPassList = null;
                                $activeLoopId = null;
                            } else {
                                $startA = $assetIdx < $passList->count() ? $assetIdx : 0;
                                $candidate = $passList[$startA];
                                $candidateVal = $this->isEligibleProjected($candidate, $history, $projHourly, $projDaily, $projLoopDaily, $projSpots, $billboard->timezone);
                                $candidateDue = $this->isDue($candidate, $virtualMs, $lastPlayedMs);

                                if ($candidateVal === ConstraintValidationService::VALID && $candidateDue) {
                                    $selected = $candidate;
                                    if ($startA + 1 >= $passList->count()) {
                                        $loopIdx = ($loopIdx + 1) % $primaryLoops->count();
                                        $assetIdx = 0;
                                        $activeLoopPassList = null;
                                        $activeLoopId = null;
                                    } else {
                                        $assetIdx = $startA + 1;
                                    }
                                } else {
                                    if ($candidateVal !== ConstraintValidationService::VALID) {
                                        $this->tallyRejection($billboard->id, $candidate->id, $candidateVal);
                                    }
                                    $loopIdx = ($loopIdx + 1) % $primaryLoops->count();
                                    $assetIdx = 0;
                                    $activeLoopPassList = null;
                                    $activeLoopId = null;
                                }
                            }
                        } else {
                            $loopIdx = ($loopIdx + 1) % $primaryLoops->count();
                            $assetIdx = 0;
                            $activeLoopPassList = null;
                            $activeLoopId = null;
                        }
                    } else {
                        // Standard loop
                        $startA = $assetIdx < $passList->count() ? $assetIdx : 0;
                        $foundInLoop = false;

                        for ($a = $startA; $a < $passList->count(); $a++) {
                            $candidate = $passList[$a];
                            $candidateVal = $this->isEligibleProjected($candidate, $history, $projHourly, $projDaily, $projLoopDaily, $projSpots, $billboard->timezone);
                            $candidateDue = $this->isDue($candidate, $virtualMs, $lastPlayedMs);

                            if ($candidateVal === ConstraintValidationService::VALID && $candidateDue) {
                                $selected = $candidate;
                                $foundInLoop = true;
                                if ($a + 1 >= $passList->count()) {
                                    $loopIdx = ($loopIdx + 1) % $primaryLoops->count();
                                    $assetIdx = 0;
                                    $activeLoopPassList = null;
                                    $activeLoopId = null;
                                } else {
                                    $assetIdx = $a + 1;
                                }
                                break;
                            } else {
                                if ($candidateVal !== ConstraintValidationService::VALID) {
                                    $this->tallyRejection($billboard->id, $candidate->id, $candidateVal);
                                }
                            }
                        }

                        if (!$foundInLoop) {
                            $loopIdx = ($loopIdx + 1) % $primaryLoops->count();
                            $assetIdx = 0;
                            $activeLoopPassList = null;
                            $activeLoopId = null;
                        }
                    }

                    // If primary loop yielded no eligible assets, check campaign-specific fallbacks
                    if (!$selected && !empty($loop->campaign_id)) {
                        $campaignId = $loop->campaign_id;
                        $cFallbacks = $campaignFallbackLoops->get($campaignId);

                        if ($cFallbacks && $cFallbacks->isNotEmpty()) {
                            $cCursor = $campaignFallbackCursors[$campaignId] ?? 0;
                            $fbAttempts = 0;

                            while ($fbAttempts < $cFallbacks->count() && !$selected) {
                                $fbLoop = $cFallbacks[$cCursor % $cFallbacks->count()];
                                $fbPassList = $buildPassList($fbLoop);
                                $startA = $campaignFallbackAssetCursors[$fbLoop->id] ?? 0;

                                for ($a = 0; $a < $fbPassList->count(); $a++) {
                                    $candidate = $fbPassList[($startA + $a) % $fbPassList->count()];
                                    $fbVal = $this->isEligibleProjected($candidate, $history, $projHourly, $projDaily, $projLoopDaily, $projSpots, $billboard->timezone);
                                    if ($fbVal === ConstraintValidationService::VALID) {
                                        $selected = $candidate;
                                        $campaignFallbackAssetCursors[$fbLoop->id] = ($startA + $a + 1) % $fbPassList->count();
                                        $campaignFallbackCursors[$campaignId] = ($cCursor + 1) % $cFallbacks->count();
                                        break;
                                    } else {
                                        $this->tallyRejection($billboard->id, $candidate->id, $fbVal);
                                    }
                                }

                                $cCursor++;
                                $fbAttempts++;
                            }
                        }
                    }

                    $loopsChecked++;
                }
            }

            // If no primary loop and no campaign fallback yielded an asset, try global fallback loops
            if (!$selected && ($globalFallbackLoops->isNotEmpty() || $allFallbackLoops->isNotEmpty())) {
                $fbCandidates = $globalFallbackLoops->isNotEmpty() ? $globalFallbackLoops : $allFallbackLoops;
                $fbAttempts = 0;

                while ($fbAttempts < $fbCandidates->count() && !$selected) {
                    $fbLoop = $fbCandidates[$globalFallbackLoopIndex % $fbCandidates->count()];
                    $fbPassList = $buildPassList($fbLoop);
                    $startA = $globalFallbackAssetCursors[$fbLoop->id] ?? 0;

                    for ($a = 0; $a < $fbPassList->count(); $a++) {
                        $candidate = $fbPassList[($startA + $a) % $fbPassList->count()];
                        $fbVal = $this->isEligibleProjected($candidate, $history, $projHourly, $projDaily, $projLoopDaily, $projSpots, $billboard->timezone);
                        if ($fbVal === ConstraintValidationService::VALID) {
                            $selected = $candidate;
                            $globalFallbackAssetCursors[$fbLoop->id] = ($startA + $a + 1) % $fbPassList->count();
                            $globalFallbackLoopIndex = ($globalFallbackLoopIndex + 1) % $fbCandidates->count();
                            break;
                        } else {
                            $this->tallyRejection($billboard->id, $candidate->id, $fbVal);
                        }
                    }

                    $fbAttempts++;
                    if (!$selected) {
                        $globalFallbackLoopIndex = ($globalFallbackLoopIndex + 1) % $fbCandidates->count();
                    }
                }
            }

            // Emergency safety net
            if (!$selected) {
                $anyWithSpots = MediaAsset::where('is_synced', true)
                    ->where(function ($q) {
                        $q->where('play_spots_remaining', '>', 0)
                          ->orWhereHas('loop', fn ($l) => $l->where('is_fallback', true));
                    })
                    ->get()
                    ->filter(function ($a) use ($billboard, $projSpots, $secondsPerSpot) {
                        if (!$this->isAssignedToBillboard($a, $billboard)) {
                            return false;
                        }
                        if ($a->isFallback()) {
                            return true;
                        }
                        $spotsNeeded = $a->spotFootprint($secondsPerSpot);
                        return ($a->play_spots_remaining - ($projSpots[$a->id] ?? 0)) >= $spotsNeeded;
                    });
                if ($anyWithSpots->isNotEmpty()) {
                    $selected = $anyWithSpots->random();
                }
            }

            if ($selected) {
                $history[] = $selected->id;
                // Tally this scheduled spot so the next iteration sees the consumed
                // hourly/daily/loop budget and yields to the fallback once capped.
                $projHourly[$selected->id] = ($projHourly[$selected->id] ?? 0) + 1;
                $projDaily[$selected->id] = ($projDaily[$selected->id] ?? 0) + 1;
                $projSpots[$selected->id] = ($projSpots[$selected->id] ?? 0) + $selected->spotFootprint($secondsPerSpot);
                if ($selected->loop_id) {
                    $projLoopDaily[$selected->loop_id] = ($projLoopDaily[$selected->loop_id] ?? 0)
                        + $selected->spotFootprint($secondsPerSpot);
                }
                // Advance pacing state: record this play's virtual time and move the
                // clock forward by the clip's airtime for the next iteration.
                $lastPlayedMs[$selected->id] = $virtualMs;
                $virtualMs += (int) round(($selected->duration_secs ?? 0) * 1000);
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
        array $history,
        array $projHourly,
        array $projDaily,
        array $projLoopDaily,
        array $projSpots = [],
        ?string $timezone = null
    ): string {
        $ph = $projHourly[$asset->id] ?? 0;
        $pd = $projDaily[$asset->id] ?? 0;
        $pl = $asset->loop_id ? ($projLoopDaily[$asset->loop_id] ?? 0) : 0;
        $ps = $projSpots[$asset->id] ?? 0;

        return $this->constraintValidator->validate($asset, $history, null, $ph, $pd, $pl, $timezone, $ps);
    }

    /**
     * Pacing gate (rule 4): an asset with a per-hour cap may only be scheduled once
     * PACING_FACTOR of its ideal inter-play interval (3600s / maxPlaysPerHour) has
     * elapsed since its last play, spreading its plays across the hour rather than
     * letting them bunch up. Distribution only — never used to reject billing logs.
     *
     * @param array<string, int> $lastPlayedMs  asset_id => last (virtual/real) play epoch ms
     */
    private function isDue(MediaAsset $asset, int $virtualMs, array $lastPlayedMs): bool
    {
        if (empty($asset->max_plays_per_hour)) {
            return true;
        }
        $last = $lastPlayedMs[$asset->id] ?? null;
        if ($last === null) {
            return true;
        }
        $idealIntervalMs = 3600000 / $asset->max_plays_per_hour;

        return ($virtualMs - $last) >= self::PACING_FACTOR * $idealIntervalMs;
    }

    private function isAssignedToBillboard(MediaAsset $asset, Billboard $billboard): bool
    {
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

        // No assignment at all — not visible
        return false;
    }

    private function tallyRejection(string $billboardId, string $assetId, string $reason): void
    {
        if ($reason === ConstraintValidationService::VALID) {
            return;
        }

        $date = now()->format('Y-m-d');
        \Illuminate\Support\Facades\DB::table('queue_rejection_stats')
            ->upsert(
                [
                    ['billboard_id' => $billboardId, 'asset_id' => $assetId, 'reason' => $reason, 'date' => $date, 'count' => 1]
                ],
                ['billboard_id', 'asset_id', 'reason', 'date'],
                ['count' => \Illuminate\Support\Facades\DB::raw('queue_rejection_stats.count + 1')]
            );
    }
}
