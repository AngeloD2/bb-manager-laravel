<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaLoopResource;
use App\Models\MediaLoop;
use App\Services\DeviceNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * LoopController
 *
 * Admin CRUD for media_loops:
 *  GET    /api/v1/admin/loops
 *  POST   /api/v1/admin/loops
 *  PUT    /api/v1/admin/loops/{loop}
 *  DELETE /api/v1/admin/loops/{loop}
 *  PUT    /api/v1/admin/loops/{loop}/reorder
 */
class LoopController extends Controller
{
    public function __construct(private DeviceNotifier $notifier) {}

    public function index(): AnonymousResourceCollection
    {
        $loops = MediaLoop::withCount('assets')
            ->orderBy('order_index')
            ->get();

        return MediaLoopResource::collection($loops);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:120'],
            'parent_loop_id' => ['nullable', 'uuid', 'exists:media_loops,id'],
            'is_fallback'      => ['boolean'],
            'is_global'        => ['boolean'],
            'max_daily_spots' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'assigned_devices' => ['nullable', 'array'],
        ]);

        $loop = MediaLoop::create($data);

        $this->notifier->notifyScheduleChanged();

        return response()->json(new MediaLoopResource($loop), 201);
    }

    public function update(Request $request, MediaLoop $loop): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:120'],
            'parent_loop_id' => ['nullable', 'uuid', Rule::exists('media_loops', 'id')->whereNot('id', $loop->id)],
            'is_fallback'      => ['sometimes', 'boolean'],
            'is_global'        => ['sometimes', 'boolean'],
            'max_daily_spots' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'assigned_devices' => ['nullable', 'array'],
        ]);

        $loop->update($data);

        $this->notifier->notifyScheduleChanged();

        return response()->json(new MediaLoopResource($loop));
    }

    public function destroy(MediaLoop $loop): JsonResponse
    {
        // Null-out child asset references before soft-deleting the loop.
        // The FK nullOnDelete trigger only fires on hard DELETE, not soft deletes.
        \App\Models\MediaAsset::where('loop_id', $loop->id)
            ->update(['loop_id' => null]);

        $loop->delete();

        $this->notifier->notifyScheduleChanged();

        return response()->json(['message' => 'Loop deleted.'], 200);
    }

    public function reorder(Request $request, MediaLoop $loop): JsonResponse
    {
        $data = $request->validate([
            'asset_ids'   => ['required', 'array'],
            'asset_ids.*' => ['required', 'uuid', Rule::exists('media_assets', 'id')->where('loop_id', $loop->id)],
        ]);

        foreach ($data['asset_ids'] as $index => $assetId) {
            \App\Models\MediaAsset::where('id', $assetId)->update(['order_index' => $index]);
        }

        $this->notifier->notifyScheduleChanged();

        return response()->json(['message' => 'Loop assets reordered.']);
    }

    public function reorderLoops(Request $request): JsonResponse
    {
        $data = $request->validate([
            'loop_ids'   => ['required', 'array'],
            'loop_ids.*' => ['required', 'uuid', Rule::exists('media_loops', 'id')],
        ]);

        foreach ($data['loop_ids'] as $index => $loopId) {
            \App\Models\MediaLoop::where('id', $loopId)->update(['order_index' => $index]);
        }

        $this->notifier->notifyScheduleChanged();

        return response()->json(['message' => 'Loops reordered.']);
    }
}
