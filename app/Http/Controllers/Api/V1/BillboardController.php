<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * BillboardController
 *
 * Provisions and manages billboard credentials.
 *
 *  GET    /api/v1/admin/billboards           - list all billboards
 *  POST   /api/v1/admin/billboards           - provision a new billboard + return Sanctum token
 *  DELETE /api/v1/admin/billboards/{billboard}  - decommission a billboard (revoke tokens)
 */
class BillboardController extends Controller
{
    public function index(): JsonResponse
    {
        $secondsPerSpot = (int) (\App\Models\Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);

        $billboards = Billboard::latest('last_seen_at')->get()->map(function (Billboard $d) use ($secondsPerSpot) {
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
                'zone_id'     => $d->zone_id,
                'timezone'     => $d->timezone,
                'is_online'    => $d->is_online,
                'is_frozen'    => $d->is_frozen,
                'is_blacked_out' => $d->is_blacked_out,
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

        return response()->json(['data' => $billboards]);
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
            'zone_id' => ['nullable', 'uuid', 'exists:zones,id'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'active_hours_start' => ['nullable', 'date_format:H:i'],
            'active_hours_end'   => ['nullable', 'date_format:H:i'],
            'password' => ['nullable', 'string', 'min:4', 'max:120'],
        ]);

        $billboard = Billboard::create(\Illuminate\Support\Arr::except($data, 'password'));
        $password = $this->assignPassword($billboard, $data['password'] ?? null);

        return response()->json([
            'data' => [
                'billboard' => [
                    'id'       => $billboard->id,
                    'name'     => $billboard->name,
                    'location' => $billboard->location,
                    'zone_id' => $billboard->zone_id,
                    'timezone' => $billboard->timezone,
                    'active_hours_start' => $billboard->active_hours_start,
                    'active_hours_end' => $billboard->active_hours_end,
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
    private function assignPassword(Billboard $billboard, ?string $plain): string
    {
        if ($plain === null || $plain === '') {
            do {
                $plain = Billboard::generatePassword();
            } while (Billboard::where('password_fingerprint', Billboard::fingerprint($plain))->exists());
        } elseif (
            Billboard::where('password_fingerprint', Billboard::fingerprint($plain))
                ->where('id', '!=', $billboard->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'password' => ['This password is already used by another billboard.'],
            ]);
        }

        $billboard->setPassword($plain);
        $billboard->save();

        return $plain;
    }

    /**
     * Exchange a billboard password for a Sanctum billboard token.
     * Public + rate-limited; the password is the board's sole identity.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $billboard = Billboard::where('password_fingerprint', Billboard::fingerprint($data['password']))->first();

        if (! $billboard) {
            throw ValidationException::withMessages([
                'password' => ['No billboard matches that password.'],
            ]);
        }

        // One active session per board: drop prior tokens before minting a fresh one.
        $billboard->tokens()->delete();
        $token = $billboard->createToken("billboard-{$billboard->id}", ['billboard:sync', 'billboard:log'])->plainTextToken;
        $billboard->heartbeat();

        return response()->json([
            'data' => [
                'billboard'    => ['id' => $billboard->id, 'name' => $billboard->name],
                'api_token' => $token,
            ],
            'message' => 'Billboard authenticated.',
        ]);
    }

    public function update(Request $request, Billboard $billboard): JsonResponse
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
            'zone_id'  => ['sometimes', 'nullable', 'uuid', 'exists:zones,id'],
            'timezone'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_frozen' => ['sometimes', 'boolean'],
            'is_blacked_out' => ['sometimes', 'boolean'],
            'active_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'active_hours_end'   => ['sometimes', 'nullable', 'date_format:H:i'],
            'password'  => ['sometimes', 'string', 'min:4', 'max:120'],
        ]);

        if (array_key_exists('password', $data)) {
            $this->assignPassword($billboard, $data['password']);
            unset($data['password']);
        }

        $billboard->update($data);

        // Both holds stop the loop; they differ in what the panel shows and in
        // what resuming does, so each end of each hold is its own command.
        //   freeze     → hold, keep the current frame
        //   unfreeze   → resume that frame where it left off
        //   blackout   → hold, show nothing
        //   unblackout → resume by replaying the held asset from its start,
        //                since there is no frame left to continue from
        $commandStr = null;

        if (isset($data['is_blacked_out'])) {
            $commandStr = $data['is_blacked_out'] ? 'blackout' : 'unblackout';
        } elseif (isset($data['is_frozen'])) {
            $commandStr = $data['is_frozen'] ? 'freeze' : 'unfreeze';
        }

        if ($commandStr !== null && config('broadcasting.default') === 'reverb') {
            try {
                broadcast(new \App\Events\BillboardCommand($billboard, $commandStr));
            } catch (\Throwable $e) {
                // A board that misses the push still picks the state up on its
                // next sync, so a broadcast failure must not fail the request --
                // but swallowing it silently hid a broken Reverb for a long
                // time, so leave a trace.
                report($e);
            }
        }

        return response()->json([
            'data' => [
                'id'        => $billboard->id,
                'name'      => $billboard->name,
                'is_frozen' => $billboard->is_frozen,
                'is_blacked_out' => $billboard->is_blacked_out,
            ],
            'message' => 'Billboard updated.',
        ]);
    }

    public function destroy(Billboard $billboard): JsonResponse
    {
        $billboardId = $billboard->id;

        $billboard->tokens()->delete();
        $billboard->delete();

        // Remove this billboard from assigned_billboards on all assets and loops.
        foreach (\App\Models\MediaAsset::whereJsonContains('assigned_billboards', $billboardId)->get() as $asset) {
            $asset->assigned_billboards = array_values(array_filter($asset->assigned_billboards, fn($id) => $id !== $billboardId));
            $asset->save();
        }

        foreach (\App\Models\MediaLoop::whereJsonContains('assigned_billboards', $billboardId)->get() as $loop) {
            $loop->assigned_billboards = array_values(array_filter($loop->assigned_billboards, fn($id) => $id !== $billboardId));
            $loop->save();
        }

        return response()->json(['message' => 'Billboard decommissioned and tokens revoked.']);
    }

    public function schedule(Billboard $billboard): JsonResponse
    {
        if (!$billboard->active_hours_start || !$billboard->active_hours_end) {
            return response()->json([
                'active_window' => null,
                'schedules' => []
            ]);
        }

        $tz = $billboard->timezone ?? 'UTC';
        $now = now($tz);
        $startStr = $now->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($billboard->active_hours_start)->format('H:i:s');
        $endStr = $now->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($billboard->active_hours_end)->format('H:i:s');
        
        $start = \Carbon\Carbon::parse($startStr, $tz);
        $end = \Carbon\Carbon::parse($endStr, $tz);
        if ($end->lessThan($start)) {
            $end->addDay();
        }

        $availableSeconds = $start->diffInSeconds($end);
        $startSecs = $start->timestamp;

        $loops = \App\Models\MediaLoop::with('assets')->get()->filter(function ($loop) use ($billboard) {
            if ($loop->is_global) return true;
            if (empty($loop->assigned_billboards)) return false;
            return in_array($billboard->id, $loop->assigned_billboards);
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
                'start' => \Carbon\Carbon::parse($billboard->active_hours_start)->format('H:i:s'),
                'end' => \Carbon\Carbon::parse($billboard->active_hours_end)->format('H:i:s')
            ],
            'schedules' => $schedules
        ]);
    }

    public function updateLoopOrder(Request $request, Billboard $billboard): JsonResponse
    {
        $data = $request->validate([
            'loop_ids'   => ['required', 'array'],
            'loop_ids.*' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('media_loops', 'id')],
        ]);

        $billboard->update(['loop_orders' => $data['loop_ids']]);

        app(\App\Services\BillboardNotifier::class)->notifyBillboard($billboard);

        return response()->json(['message' => 'Billboard loops reordered.']);
    }
}
