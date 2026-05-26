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

        // Broadcast via Reverb if configured (non-blocking)
        if (config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new \App\Events\OverrideDispatched($override, $asset, $device));
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
}
