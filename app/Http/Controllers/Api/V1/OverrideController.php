<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\TimelineOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OverrideController
 *
 * POST /api/v1/admin/overrides
 *
 * The Override Protocol: pushes a high-priority "Play Next" command to a
 * specific billboard. The command is stored in timeline_overrides and
 * delivered (then consumed) on the billboard's next GET /sync call.
 *
 * If Laravel Reverb is configured, it also broadcasts the override via
 * WebSocket so billboards with persistent connections receive it instantly.
 */
class OverrideController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id'  => ['required', 'uuid', 'exists:media_assets,id'],
            'billboard_id' => ['required', 'uuid', 'exists:billboards,id'],
        ]);

        $asset  = MediaAsset::findOrFail($data['asset_id']);
        $billboard = Billboard::findOrFail($data['billboard_id']);

        // Delete any existing unconsumed overrides for this billboard so only one is active at a time
        TimelineOverride::where('billboard_id', $billboard->id)
            ->where('consumed', false)
            ->delete();

        // Create the override command record
        $override = TimelineOverride::create([
            'asset_id'  => $asset->id,
            'billboard_id' => $billboard->id,
            'consumed'  => false,
        ]);

        // Inject the override directly into the server's generated timeline queue
        app(\App\Services\QueueGenerationService::class)->injectOverride($billboard, $asset);

        // Broadcast via Reverb if configured (non-blocking).
        // Only push the instant override when the asset has finished processing —
        // otherwise its download_url would point at an object still being
        // transcoded/moved. For a still-processing asset the override is left
        // queued (unconsumed) and delivered via /sync the moment AssetProcessingJob
        // completes and notifies the billboard.
        if ($asset->is_synced && config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new \App\Events\BillboardCommand($billboard, 'override', [
                    'override_id'   => $override->id,
                    'asset_id'      => $asset->id,
                    'asset_name'    => $asset->name,
                    'file_type'     => $asset->file_type,
                    'duration_secs' => $asset->duration_secs,
                    'loop_id'       => $asset->loop_id,
                    'download_url'  => $asset->deliveryUrl(),
                ]));
            } catch (\Throwable) {
                // WebSocket broadcast failed — polling fallback will handle it.
            }
        }

        return response()->json([
            'message' => "Override queued for billboard \"{$billboard->name}\".",
            'data'    => [
                'override_id' => $override->id,
                'asset_id'    => $asset->id,
                'asset_name'  => $asset->name,
                'billboard_id'   => $billboard->id,
                'billboard_name' => $billboard->name,
            ],
        ], 201);
    }

    /**
     * DELETE /api/v1/admin/overrides
     * Query param: billboard_id (required)
     *
     * Cancels all pending unconsumed overrides queued for the given billboard.
     * Returns 200 whether or not an override was pending (idempotent).
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'billboard_id' => ['required', 'uuid', 'exists:billboards,id'],
        ]);

        $billboard = Billboard::findOrFail($data['billboard_id']);

        // Delete all unconsumed overrides to ensure the queue is completely clean
        TimelineOverride::where('billboard_id', $billboard->id)
            ->where('consumed', false)
            ->delete();

        // Always remove the override from the server's generated timeline queue,
        // even if it was marked as consumed (since it might still be sitting in the cache)
        app(\App\Services\QueueGenerationService::class)->cancelOverride($billboard);

        // Broadcast the cancellation so connected players can clear their queue,
        // even if it was marked as consumed on the server, the player might still have it queued.
        if (config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new \App\Events\BillboardCommand($billboard, 'override_cancelled', []));
            } catch (\Throwable) {
                // Non-blocking; billboard will reconcile on next /sync poll.
            }
        }

        return response()->json([
            'message'   => 'Pending override cancelled.',
            'cancelled' => true,
        ]);
    }
}
