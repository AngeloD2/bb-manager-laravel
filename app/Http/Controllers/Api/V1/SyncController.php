<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaAssetResource;
use App\Http\Resources\Api\V1\MediaFolderResource;
use App\Services\DeviceSyncService;
use App\Services\TokenManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * SyncController
 *
 * Handles the billboard device ↔ server communication:
 *  GET  /api/v1/sync   — pull current state (folders, eligible assets, overrides)
 *  POST /api/v1/logs   — push bulk playback logs for token deduction
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly DeviceSyncService   $syncService,
        private readonly TokenManagerService $tokenManager
    ) {}

    /**
     * GET /api/v1/sync
     *
     * Returns the full device payload:
     *  - All folders
     *  - Eligible (constraint-passing) primary assets with delivery URLs
     *  - Fallback assets
     *  - Pending override commands (consumed on delivery)
     */
    public function sync(Request $request): JsonResponse
    {
        /** @var \App\Models\Device $device */
        $device  = $request->user();
        $payload = $this->syncService->buildPayload($device);

        return response()->json([
            'data' => [
                'device'    => [
                    'id'       => $device->id,
                    'name'     => $device->name,
                    'geo_zone' => $device->geo_zone,
                ],
                'folders'           => MediaFolderResource::collection($payload['folders']),
                'eligible_assets'   => MediaAssetResource::collection($payload['eligible_assets']),
                'fallback_assets'   => MediaAssetResource::collection($payload['fallback_assets']),
                'pending_overrides' => $payload['pending_overrides']->map(fn ($o) => [
                    'id'       => $o->id,
                    'asset'    => new MediaAssetResource($o->asset),
                ]),
                'synced_at' => $payload['synced_at'],
            ],
        ]);
    }

    /**
     * POST /api/v1/logs
     *
     * Accepts a batch of playback log entries from a billboard device.
     * Validates token budgets and persists the accepted entries.
     *
     * Body: { "logs": [{ "asset_id": "...", "played_at": "ISO8601", "was_override": false }] }
     */
    public function storeLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'logs'                  => ['required', 'array', 'min:1', 'max:500'],
            'logs.*.asset_id'       => ['required', 'uuid', 'exists:media_assets,id'],
            'logs.*.played_at'      => ['required', 'date'],
            'logs.*.was_override'   => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var \App\Models\Device $device */
        $device = $request->user();

        $result = $this->tokenManager->processBatch($device, $request->input('logs'));

        return response()->json([
            'data'    => $result,
            'message' => "Batch processed: {$result['accepted']} accepted, {$result['rejected']} rejected.",
        ], 200);
    }

    /**
     * GET /api/v1/assets/{asset}/download
     *
     * Returns a short-lived S3 presigned GET URL for local edge caching
     * on the billboard device.
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
}
