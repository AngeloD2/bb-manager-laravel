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
                'billboard'    => [
                    'id'        => $billboard->id,
                    'name'      => $billboard->name,
                    'geo_zone'  => $billboard->geo_zone,
                    'is_frozen' => (bool) $billboard->is_frozen,
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
            'logs'                      => ['required', 'array', 'min:1', 'max:500'],
            'logs.*.asset_id'           => ['required', 'uuid', 'exists:media_assets,id'],
            'logs.*.client_event_id'    => ['required', 'uuid'],
            'logs.*.played_at'          => ['required', 'date'],
            'logs.*.was_override'       => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();

        $result = $this->tokenManager->processBatch($billboard, $request->input('logs'));

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
            'asset_id'   => ['required', 'uuid', 'exists:media_assets,id'],
            'started_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Billboard $billboard */
        $billboard = $request->user();
        $asset  = \App\Models\MediaAsset::findOrFail($request->input('asset_id'));

        // Broadcast via Reverb/Pusher if configured
        try {
            broadcast(new \App\Events\PlaybackStarted($billboard, $asset, $request->input('started_at')));
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

        $asset = \App\Models\MediaAsset::with('loop')->find($assetId);

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
        
        $queue = $queueService->getUpcomingQueue($billboard, 12);
        
        return response()->json(['data' => $queue]);
    }
}

