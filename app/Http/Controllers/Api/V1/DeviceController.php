<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $secondsPerSpot = (int) (\App\Models\Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);

        $devices = Device::latest('last_seen_at')->get()->map(function (Device $d) use ($secondsPerSpot) {
            $totalSpots = 0;
            $playedSpots = 0;
            $openSpots = 0;

            if ($d->active_hours_start && $d->active_hours_end) {
                $tz = $d->timezone ?? 'UTC';
                $now = now($tz);
                $startStr = $now->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($d->active_hours_start)->format('H:i:s');
                $endStr = $now->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($d->active_hours_end)->format('H:i:s');
                
                $start = \Carbon\Carbon::parse($startStr, $tz);
                $end = \Carbon\Carbon::parse($endStr, $tz);
                if ($end->lessThan($start)) {
                    $end->addDay();
                }

                $durationSecs = $start->diffInSeconds($end);
                $totalSpots = (int) floor($durationSecs / $secondsPerSpot);

                // calculate open spots — sum slot footprints so a long clip counts
                // as the multiple slots it actually occupies, matching total_spots.
                $playedSpots = (int) $d->playbackLogs()->whereBetween('played_at', [$start, $end])->sum('spot_spent');
                $openSpots = max(0, $totalSpots - $playedSpots);
            }

            return [
                'id'           => $d->id,
                'name'         => $d->name,
                'location'     => $d->location,
                'geo_zone'     => $d->geo_zone,
                'timezone'     => $d->timezone,
                'is_online'    => $d->is_online,
                'is_frozen'    => $d->is_frozen,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'active_hours_start' => $d->active_hours_start,
                'active_hours_end'   => $d->active_hours_end,
                'password'    => $d->plain_password,
                'total_spots' => $totalSpots,
                'played_spots' => $playedSpots,
                'open_spots' => $openSpots,
                'loop_orders' => $d->loop_orders ?? [],
            ];
        });

        return response()->json(['data' => $devices]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('active_hours_start') && $request->input('active_hours_start')) {
            $request->merge(['active_hours_start' => substr($request->input('active_hours_start'), 0, 5)]);
        }
        if ($request->has('active_hours_end') && $request->input('active_hours_end')) {
            $request->merge(['active_hours_end' => substr($request->input('active_hours_end'), 0, 5)]);
        }

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:200'],
            'geo_zone' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'active_hours_start' => ['nullable', 'date_format:H:i'],
            'active_hours_end'   => ['nullable', 'date_format:H:i'],
            'password' => ['nullable', 'string', 'min:4', 'max:120'],
        ]);

        $device = Device::create(\Illuminate\Support\Arr::except($data, 'password'));
        $password = $this->assignPassword($device, $data['password'] ?? null);

        return response()->json([
            'data' => [
                'device' => [
                    'id'       => $device->id,
                    'name'     => $device->name,
                    'location' => $device->location,
                    'geo_zone' => $device->geo_zone,
                    'timezone' => $device->timezone,
                    'active_hours_start' => $device->active_hours_start,
                    'active_hours_end' => $device->active_hours_end,
                ],
                'password' => $password,
            ],
            'message' => 'Billboard provisioned. Enter this password in the player to connect.',
        ], 201);
    }

    /**
     * Set a billboard's player password. Generates a unique one when $plain is
     * empty; otherwise verifies it isn't already used by another billboard.
     */
    private function assignPassword(Device $device, ?string $plain): string
    {
        if ($plain === null || $plain === '') {
            do {
                $plain = Device::generatePassword();
            } while (Device::where('password_fingerprint', Device::fingerprint($plain))->exists());
        } elseif (
            Device::where('password_fingerprint', Device::fingerprint($plain))
                ->where('id', '!=', $device->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'password' => ['This password is already used by another billboard.'],
            ]);
        }

        $device->setPassword($plain);
        $device->save();

        return $plain;
    }

    /**
     * Exchange a billboard password for a Sanctum device token.
     * Public + rate-limited; the password is the board's sole identity.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $device = Device::where('password_fingerprint', Device::fingerprint($data['password']))->first();

        if (! $device) {
            throw ValidationException::withMessages([
                'password' => ['No billboard matches that password.'],
            ]);
        }

        // One active session per board: drop prior tokens before minting a fresh one.
        $device->tokens()->delete();
        $token = $device->createToken("device-{$device->id}", ['device:sync', 'device:log'])->plainTextToken;
        $device->heartbeat();

        return response()->json([
            'data' => [
                'device'    => ['id' => $device->id, 'name' => $device->name],
                'api_token' => $token,
            ],
            'message' => 'Billboard authenticated.',
        ]);
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        if ($request->has('active_hours_start') && $request->input('active_hours_start')) {
            $request->merge(['active_hours_start' => substr($request->input('active_hours_start'), 0, 5)]);
        }
        if ($request->has('active_hours_end') && $request->input('active_hours_end')) {
            $request->merge(['active_hours_end' => substr($request->input('active_hours_end'), 0, 5)]);
        }

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:120'],
            'location'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'geo_zone'  => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_frozen' => ['sometimes', 'boolean'],
            'active_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'active_hours_end'   => ['sometimes', 'nullable', 'date_format:H:i'],
            'password'  => ['sometimes', 'string', 'min:4', 'max:120'],
        ]);

        if (array_key_exists('password', $data)) {
            $this->assignPassword($device, $data['password']);
            unset($data['password']);
        }

        $device->update($data);

        if (isset($data['is_frozen'])) {
            $commandStr = $data['is_frozen'] ? 'freeze' : 'unfreeze';
            if (config('broadcasting.default') === 'reverb') {
                try {
                    broadcast(new \App\Events\DeviceCommand($device, $commandStr));
                } catch (\Throwable) {}
            }
        }

        return response()->json([
            'data' => [
                'id'        => $device->id,
                'name'      => $device->name,
                'is_frozen' => $device->is_frozen,
            ],
            'message' => 'Device updated.',
        ]);
    }

    public function destroy(Device $device): JsonResponse
    {
        $deviceId = $device->id;

        $device->tokens()->delete();
        $device->delete();

        // Remove this device from assigned_devices on all assets and loops.
        foreach (\App\Models\MediaAsset::whereJsonContains('assigned_devices', $deviceId)->get() as $asset) {
            $asset->assigned_devices = array_values(array_filter($asset->assigned_devices, fn($id) => $id !== $deviceId));
            $asset->save();
        }

        foreach (\App\Models\MediaLoop::whereJsonContains('assigned_devices', $deviceId)->get() as $loop) {
            $loop->assigned_devices = array_values(array_filter($loop->assigned_devices, fn($id) => $id !== $deviceId));
            $loop->save();
        }

        return response()->json(['message' => 'Device decommissioned and tokens revoked.']);
    }

    public function schedule(Device $device): JsonResponse
    {
        if (!$device->active_hours_start || !$device->active_hours_end) {
            return response()->json([
                'active_window' => null,
                'schedules' => []
            ]);
        }

        $tz = $device->timezone ?? 'UTC';
        $now = now($tz);
        $startStr = $now->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($device->active_hours_start)->format('H:i:s');
        $endStr = $now->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($device->active_hours_end)->format('H:i:s');
        
        $start = \Carbon\Carbon::parse($startStr, $tz);
        $end = \Carbon\Carbon::parse($endStr, $tz);
        if ($end->lessThan($start)) {
            $end->addDay();
        }

        $availableSeconds = $start->diffInSeconds($end);
        $startSecs = $start->timestamp;

        $loops = \App\Models\MediaLoop::with('assets')->get()->filter(function ($loop) use ($device) {
            if ($loop->is_global) return true;
            if (empty($loop->assigned_devices)) return false;
            return in_array($device->id, $loop->assigned_devices);
        });

        $constrainedLoops = [];
        $continuousLoops = [];

        foreach ($loops as $loop) {
            $assets = $loop->assets->sortBy('order_index');
            $duration = $assets->sum('duration_secs');
            if ($duration <= 0) continue;

            $targetPlays = $loop->max_daily_spots ?? 0;
            if ($targetPlays > 0) {
                $constrainedLoops[] = [
                    'loop_id' => $loop->id,
                    'duration' => $duration,
                    'target_plays' => $targetPlays
                ];
            } else {
                $continuousLoops[] = [
                    'loop_id' => $loop->id,
                    'type' => 'continuous'
                ];
            }
        }

        $events = [];
        foreach ($constrainedLoops as $cl) {
            $interval = $availableSeconds / max(1, $cl['target_plays']);
            for ($i = 0; $i < $cl['target_plays']; $i++) {
                $events[] = [
                    'loop_id' => $cl['loop_id'],
                    'ideal_time' => $startSecs + ($i * $interval),
                    'duration' => $cl['duration']
                ];
            }
        }

        usort($events, fn($a, $b) => $a['ideal_time'] <=> $b['ideal_time']);

        $currentTime = $startSecs;
        $scheduledPlays = [];

        foreach ($events as $event) {
            $actualTime = max($currentTime, $event['ideal_time']);
            
            if ($actualTime + $event['duration'] > $end->timestamp) {
                continue;
            }

            if (!isset($scheduledPlays[$event['loop_id']])) {
                $scheduledPlays[$event['loop_id']] = [];
            }

            $formattedTime = \Carbon\Carbon::createFromTimestamp($actualTime, $tz)->format('H:i:s');
            $scheduledPlays[$event['loop_id']][] = [
                'time' => $formattedTime,
                'timestamp' => $actualTime
            ];

            $currentTime = $actualTime + $event['duration'];
        }

        $schedules = $continuousLoops;
        foreach ($scheduledPlays as $loopId => $plays) {
            $schedules[] = [
                'loop_id' => $loopId,
                'type' => 'constrained',
                'plays' => $plays
            ];
        }

        return response()->json([
            'active_window' => [
                'start' => \Carbon\Carbon::parse($device->active_hours_start)->format('H:i:s'),
                'end' => \Carbon\Carbon::parse($device->active_hours_end)->format('H:i:s')
            ],
            'schedules' => $schedules
        ]);
    }

    public function updateLoopOrder(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'loop_ids'   => ['required', 'array'],
            'loop_ids.*' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('media_loops', 'id')],
        ]);

        $device->update(['loop_orders' => $data['loop_ids']]);

        app(\App\Services\DeviceNotifier::class)->notifyDevice($device);

        return response()->json(['message' => 'Device loops reordered.']);
    }
}
