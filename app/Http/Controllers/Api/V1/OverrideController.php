<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
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
 * specific billboard device. The command is stored in timeline_overrides and
 * delivered (then consumed) on the device's next GET /sync call.
 *
 * If Laravel Reverb is configured, it also broadcasts the override via
 * WebSocket so devices with persistent connections receive it instantly.
 */
class OverrideController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id'  => ['required', 'uuid', 'exists:media_assets,id'],
            'device_id' => ['required', 'uuid', 'exists:devices,id'],
        ]);

        $asset  = MediaAsset::findOrFail($data['asset_id']);
        $device = Device::findOrFail($data['device_id']);

        // Create the override command record
        $override = TimelineOverride::create([
            'asset_id'  => $asset->id,
            'device_id' => $device->id,
            'consumed'  => false,
        ]);

        // Inject the override directly into the server's generated timeline queue
        app(\App\Services\QueueGenerationService::class)->injectOverride($device, $asset);

        // Broadcast via Reverb if configured (non-blocking)
        if (config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new \App\Events\DeviceCommand($device, 'override', ['asset_id' => $asset->id]));
            } catch (\Throwable) {
                // WebSocket broadcast failed — polling fallback will handle it.
            }
        }

        return response()->json([
            'message' => "Override queued for device \"{$device->name}\".",
            'data'    => [
                'override_id' => $override->id,
                'asset_id'    => $asset->id,
                'asset_name'  => $asset->name,
                'device_id'   => $device->id,
                'device_name' => $device->name,
            ],
        ], 201);
    }

    /**
     * DELETE /api/v1/admin/overrides
     * Query param: device_id (required)
     *
     * Cancels the most recent unconsumed override queued for the given device.
     * Returns 200 whether or not an override was pending (idempotent).
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid', 'exists:devices,id'],
        ]);

        $device = Device::findOrFail($data['device_id']);

        $override = TimelineOverride::where('device_id', $device->id)
            ->where('consumed', false)
            ->latest()
            ->first();

        if (!$override) {
            return response()->json([
                'message'   => 'No pending override found for this device.',
                'cancelled' => false,
            ]);
        }

        $override->delete();

        // Remove the override from the server's generated timeline queue
        app(\App\Services\QueueGenerationService::class)->cancelOverride($device);

        // Broadcast the cancellation so connected players can clear their queue
        if (config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new \App\Events\DeviceCommand($device, 'override_cancelled', []));
            } catch (\Throwable) {
                // Non-blocking; device will reconcile on next /sync poll.
            }
        }

        return response()->json([
            'message'   => "Override cancelled for device \"{$device->name}\".",
            'cancelled' => true,
        ]);
    }
}
