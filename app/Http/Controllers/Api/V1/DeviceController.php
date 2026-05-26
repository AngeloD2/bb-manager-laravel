<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DeviceController
 *
 * Provisions and manages billboard device credentials.
 *
 *  GET    /api/v1/admin/devices           - list all devices
 *  POST   /api/v1/admin/devices           - provision a new device + return Sanctum token
 *  DELETE /api/v1/admin/devices/{device}  - decommission a device (revoke tokens)
 */
class DeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = Device::latest('last_seen_at')->get()->map(fn (Device $d) => [
            'id'           => $d->id,
            'name'         => $d->name,
            'location'     => $d->location,
            'geo_zone'     => $d->geo_zone,
            'is_online'    => $d->is_online,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $devices]);
    }

    /**
     * Provision a new device and return a long-lived Sanctum API token.
     * The token is returned ONCE — store it securely on the physical board.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:200'],
            'geo_zone' => ['nullable', 'string', 'max:120'],
        ]);

        $device = Device::create($data);

        // Long-lived Sanctum token with 'device' ability only
        $token = $device->createToken(
            "device-{$device->id}",
            ['device:sync', 'device:log']
        )->plainTextToken;

        return response()->json([
            'data' => [
                'device' => [
                    'id'       => $device->id,
                    'name'     => $device->name,
                    'location' => $device->location,
                    'geo_zone' => $device->geo_zone,
                ],
                'api_token' => $token,   // returned ONCE — store on the board
            ],
            'message' => 'Device provisioned. Store the API token — it will not be shown again.',
        ], 201);
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->tokens()->delete();  // revoke all Sanctum tokens
        $device->delete();

        return response()->json(['message' => 'Device decommissioned and tokens revoked.']);
    }
}
