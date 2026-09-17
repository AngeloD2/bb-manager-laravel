<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaAssetResource;
use App\Http\Resources\Api\V1\MediaLoopResource;
use App\Services\BillboardSyncService;
use App\Services\SpotManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * SyncController
 *
 * Handles the billboard ↔ server communication:
 *  GET  /api/v1/sync   — pull current state (loops, eligible assets, overrides)
 *  POST /api/v1/logs   — push bulk playback logs for spot deduction
 */
class SyncController extends Controller
{
    /** How far ahead the paged timeline projects, in plays. */
    private const TIMELINE_LOOKAHEAD = 200;

    public function __construct(
        private readonly BillboardSyncService   $syncService,
        private readonly SpotManagerService $tokenManager
    ) {}

    /**
     * GET /api/v1/sync
     *
     * Returns the full billboard payload:
     *  - All loops
     *  - Eligible (constraint-passing) primary assets with delivery URLs
     *  - Fallback assets
     *  - Pending override commands (consumed on delivery)
     */
    public function sync(Request $request): JsonResponse
    {
        /** @var \App\Models\Billboard $billboard */
        $billboard  = $request->user();
        $payload = $this->syncService->buildPayload($billboard);

        return response()->json([
            'data' => [
                // No zone is sent: zone targeting is enforced server-side at
                // sync and the player is deliberately zone-unaware.
                'billboard'    => [
                    'id'        => $billboard->id,
                    'name'      => $billboard->name,
                    'is_frozen' => (bool) $billboard->is_frozen,
                    'is_blacked_out' => (bool) $billboard->is_blacked_out,
                ],
                'loops'           => MediaLoopResource::collection($payload['loops']),
                'eligible_assets'   => MediaAssetResource::collection($payload['eligible_assets']),
                'fallback_assets'   => MediaAssetResource::collection($payload['fallback_assets']),
                'standalone_assets' => MediaAssetResource::collection($payload['standalone_assets']),
                'pending_overrides' => $payload['pending_overrides']->map(fn ($o) => [
                    'id'       => $o->id,
                    'asset'    => new MediaAssetResource($o->asset),
                ]),
                'schedule'        => $payload['schedule'],
                'quota'           => $payload['quota'],
                'broadcasting'    => $payload['broadcasting'],
                'synced_at' => $payload['synced_at'],
            ],
        ]);
    }

    /**
     * GET /api/v1/sync/ping
     *
     * Lightweight reachability probe. The billboard polls this to decide whether it
     * can flush its local log queue; `server_time` lets it correct clock skew on
     * locally-stamped played_at values. Also refreshes the billboard heartbeat.
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();
        $billboard->heartbeat();

        return response()->json([
            'ok'          => true,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/logs
     *
     * Accepts a batch of playback log entries from a billboard.
     * Validates spot budgets and persists the accepted entries.
     *
     * Body: { "logs": [{ "asset_id": "...", "played_at": "ISO8601", "was_override": false }] }
     */
    public function storeLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // May be empty: a flush can carry only rejection stats.
            'logs'                      => ['present', 'array', 'max:500'],
            'logs.*.asset_id'           => ['required', 'uuid', 'exists:media_assets,id'],
            'logs.*.client_event_id'    => ['required', 'uuid'],
            'logs.*.played_at'          => ['required', 'date'],
            'logs.*.was_override'       => ['sometimes', 'boolean'],
            'rejections'                => ['nullable', 'array', 'max:500'],
            'rejections.*.asset_id'     => ['required', 'uuid', 'exists:media_assets,id'],
            'rejections.*.reason'       => ['required', 'string'],
            'rejections.*.count'        => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();

        $result = $this->tokenManager->processBatch($billboard, $request->input('logs'), $request->input('rejections', []));

        return response()->json([
            'data'    => array_merge($result, [
                'billboard_state' => $this->syncService->billboardSpotState($billboard),
            ]),
            'message' => "Batch processed: {$result['accepted']} accepted, {$result['rejected']} rejected.",
        ], 200);
    }

    /**
     * POST /api/v1/playback/start
     *
     * Invoked by a billboard to notify that it has started playing a media asset.
     * Broadcasts the event to all listeners of the billboard's WebSocket channel.
     */
    public function reportPlaybackStart(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'asset_id'      => ['required', 'uuid', 'exists:media_assets,id'],
            'started_at'    => ['required', 'date'],
            // The length this play really runs (a video's own duration), when known.
            'duration_secs' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:86400'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();
        $asset  = \App\Models\MediaAsset::findOrFail($request->input('asset_id'));

        // Stamp the start on the server clock as the report arrives. The board
        // reports the moment playback begins, and the dashboard can place server
        // time on its own clock — the board's clock may be skewed, and the time
        // the broadcast reaches the dashboard varies with network latency.
        $startedAtMs = (int) round((defined('LARAVEL_START') ? LARAVEL_START : microtime(true)) * 1000);
        $durationSecs = $request->filled('duration_secs') ? (float) $request->input('duration_secs') : null;

        // Re-anchor the dashboard's upcoming queue on what the board actually started.
        app(\App\Services\QueueGenerationService::class)->alignToStarted($billboard, $asset->id);

        // Broadcast via Reverb/Pusher if configured
        try {
            broadcast(new \App\Events\PlaybackStarted($billboard, $asset, $request->input('started_at'), $durationSecs, $startedAtMs));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Broadcast failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => "Playback event broadcasted.",
            'data'    => [
                'billboard_id' => $billboard->id,
                'asset_id'  => $asset->id,
                'started_at'=> $request->input('started_at'),
            ],
        ], 200);
    }

    /**
     * POST /api/v1/playback/stop
     *
     * Invoked by a billboard to notify that it has stopped playback (e.g. queue exhausted).
     * Broadcasts the event to all listeners of the billboard's WebSocket channel.
     */
    public function reportPlaybackStop(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stopped_at' => ['nullable', 'date'],
            'reason'     => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();
        $stoppedAt = $request->input('stopped_at', now()->toIso8601String());
        $reason    = $request->input('reason');

        // Broadcast via Reverb/Pusher if configured
        try {
            broadcast(new \App\Events\PlaybackStopped($billboard, $stoppedAt, $reason));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Broadcast failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Playback stop event broadcasted.',
            'data'    => [
                'billboard_id' => $billboard->id,
                'stopped_at'   => $stoppedAt,
                'reason'       => $reason,
            ],
        ], 200);
    }

    /**
     * GET /api/v1/assets/{assetId}/serve
     *
     * Sanctum-authenticated auth gate: validates billboard access then issues a
     * 302 redirect to a short-lived presigned S3 URL. S3 delivers the bytes
     * directly to the billboard; this route only pays the cost of a HeadObject
     * check and redirect. The bucket must have a CORS policy allowing GET from
     * all origins so that the browser accepts the S3 response after following
     * the redirect.
     */
    public function serveAsset(Request $request, string $assetId): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();

        $asset = \App\Models\MediaAsset::with('loop.campaign')->find($assetId);

        if (!$asset) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        if (!$this->billboardCanAccessAsset($billboard, $asset)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('s3');

        if (!$disk->exists($asset->file_path)) {
            return response()->json(['message' => 'Asset file missing from storage.'], 404);
        }

        $presignedUrl = $disk->temporaryUrl($asset->file_path, now()->addSeconds(300));

        return redirect($presignedUrl, 302);
    }

    private function billboardCanAccessAsset(\App\Models\Billboard $billboard, \App\Models\MediaAsset $asset): bool
    {
        if ($asset->is_global) return true;
        if ($asset->loop && $asset->loop->is_global) return true;

        $hasAssetAssignment = !empty($asset->assigned_billboards);
        $hasLoopAssignment = $asset->loop && !empty($asset->loop->assigned_billboards);

        if ($hasAssetAssignment && in_array($billboard->id, $asset->assigned_billboards)) return true;
        if ($hasLoopAssignment && in_array($billboard->id, $asset->loop->assigned_billboards)) return true;

        if ($hasAssetAssignment || $hasLoopAssignment) {
            return false;
        }

        return true;
    }

    /**
     * GET /api/v1/assets/{asset}/download
     *
     * Returns a short-lived S3 presigned GET URL for local edge caching
     * on the billboard.
     */
    public function assetDownload(Request $request, string $assetId): JsonResponse
    {
        $asset = \App\Models\MediaAsset::findOrFail($assetId);

        return response()->json([
            'data' => [
                'asset_id'    => $asset->id,
                'download_url'=> $asset->deliveryUrl(3600),
                'expires_in'  => 3600,
            ],
        ]);
    }

    /**
     * GET /api/v1/timeline
     *
     * Returns the generated timeline preview (for the admin dashboard app).
     */
    public function timeline(Request $request, \App\Services\QueueGenerationService $queueService): JsonResponse
    {
        $billboardId = $request->query('billboard_id');
        $billboard = \App\Models\Billboard::findOrFail($billboardId);

        // Without paging params: the live queue, as the store's playback prediction expects.
        if (!$request->has('offset') && !$request->has('limit')) {
            return response()->json(['data' => $queueService->getUpcomingQueue($billboard, 12)]);
        }

        // Paged look-ahead for "Up Next": the live queue, then a read-only projection.
        $data = $request->validate([
            'offset' => ['sometimes', 'integer', 'min:0', 'max:' . (self::TIMELINE_LOOKAHEAD - 1)],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $offset = (int) ($data['offset'] ?? 0);
        $limit = min((int) ($data['limit'] ?? 10), self::TIMELINE_LOOKAHEAD - $offset);

        $upcoming = $queueService->previewQueue($billboard, $offset + $limit);
        $page = array_slice($upcoming, $offset, $limit);

        return response()->json([
            'data' => $page,
            'meta' => [
                'offset'   => $offset,
                'limit'    => $limit,
                'has_more' => count($page) === $limit && $offset + $limit < self::TIMELINE_LOOKAHEAD,
            ],
        ]);
    }
}

