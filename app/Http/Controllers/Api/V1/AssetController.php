<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaAssetResource;
use App\Jobs\AssetProcessingJob;
use App\Models\MediaAsset;
use App\Services\DeviceNotifier;
use App\Services\S3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * AssetController
 *
 * Admin CRUD for media_assets:
 *
 *  GET    /api/v1/admin/assets
 *  POST   /api/v1/admin/assets            → metadata-only creation
 *  POST   /api/v1/admin/assets/upload     → multipart file upload (file → S3)
 *  PUT    /api/v1/admin/assets/{asset}
 *  DELETE /api/v1/admin/assets/{asset}
 */
class AssetController extends Controller
{
    public function __construct(
        private readonly S3Service $s3,
        private readonly DeviceNotifier $notifier
    ) {}

    // ── Listing ───────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MediaAsset::with(['loop', 'conflicts'])->latest();

        if ($request->filled('loop_id')) {
            $query->where('loop_id', $request->loop_id);
        }

        if ($request->filled('file_type')) {
            $query->where('file_type', strtoupper($request->file_type));
        }

        if ($request->filled('geo_campaign')) {
            $query->where('geo_campaign', $request->geo_campaign);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', "%{$request->search}%")
                  ->orWhere('campaign_name', 'ilike', "%{$request->search}%");
            });
        }

        return MediaAssetResource::collection($query->paginate(50));
    }

    // ── Create (metadata only, no file) ─────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:200'],
            'file_path'             => ['required', 'string'],
            'file_type'             => ['required', 'in:VIDEO,GIF,PHOTO'],
            'loop_id'             => ['nullable', 'uuid', 'exists:media_loops,id'],
            'size_bytes'            => ['required', 'integer', 'min:1'],
            'duration_secs'         => ['nullable', 'integer', 'min:8', 'max:15'],
            'geo_campaign'          => ['nullable', 'string', 'max:120'],
            'campaign_name'         => ['nullable', 'string', 'max:200'],
            'max_plays_per_hour'    => ['nullable', 'integer', 'min:1'],
            'max_daily_plays'       => ['nullable', 'integer', 'min:1'],
            'play_spots_remaining'  => ['nullable', 'integer', 'min:0'],
            'assigned_devices'      => ['nullable', 'array'],
            'is_global'             => ['boolean'],
            'campaign_start_date'   => ['nullable', 'date_format:Y-m-d'],
            'campaign_end_date'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:campaign_start_date'],
            'playback_times'        => ['nullable', 'array'],
            'playback_times.*'      => ['string', 'regex:/^\d{2}:\d{2}$/'],
            'conflict_asset_ids'    => ['nullable', 'array'],
            'conflict_asset_ids.*'  => ['uuid', 'exists:media_assets,id'],
        ]);

        $assetData = collect($data)->except('conflict_asset_ids')->toArray();
        $asset = MediaAsset::create(array_merge($assetData, ['is_synced' => false]));

        if ($request->has('conflict_asset_ids')) {
            $asset->syncConflicts($request->input('conflict_asset_ids', []));
        }

        $this->notifier->notifyScheduleChanged();

        return response()->json(new MediaAssetResource($asset->load(['loop', 'conflicts'])), 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, MediaAsset $asset): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:200'],
            'loop_id'             => ['nullable', 'uuid', 'exists:media_loops,id'],
            'geo_campaign'          => ['nullable', 'string', 'max:120'],
            'campaign_name'         => ['nullable', 'string', 'max:200'],
            'max_plays_per_hour'    => ['nullable', 'integer', 'min:1'],
            'max_daily_plays'       => ['nullable', 'integer', 'min:1'],
            'play_spots_remaining'  => ['sometimes', 'integer', 'min:0'],
            'assigned_devices'      => ['nullable', 'array'],
            'is_global'             => ['sometimes', 'boolean'],
            'campaign_start_date'   => ['nullable', 'date_format:Y-m-d'],
            'campaign_end_date'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:campaign_start_date'],
            'playback_times'        => ['nullable', 'array'],
            'playback_times.*'      => ['string', 'regex:/^\d{2}:\d{2}$/'],
            'conflict_asset_ids'    => ['nullable', 'array'],
            'conflict_asset_ids.*'  => ['uuid', 'exists:media_assets,id'],
        ]);

        $assetData = collect($data)->except('conflict_asset_ids')->toArray();
        $asset->update($assetData);

        if ($request->has('conflict_asset_ids')) {
            $asset->syncConflicts($request->input('conflict_asset_ids', []));
        }

        $this->notifier->notifyScheduleChanged();

        return response()->json(new MediaAssetResource($asset->fresh(['loop', 'conflicts'])));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(MediaAsset $asset): JsonResponse
    {
        // Attempt S3 cleanup; log failures but don't block the response.
        try {
            $this->s3->deleteObject($asset->file_path);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AssetController: S3 delete failed', [
                'asset_id'  => $asset->id,
                'file_path' => $asset->file_path,
                'error'     => $e->getMessage(),
            ]);
        }

        $asset->delete();

        $this->notifier->notifyScheduleChanged();

        return response()->json(['message' => 'Asset deleted.']);
    }

    // ── File Upload ─────────────────────────────────────────────────────────

    /**
     * POST /api/v1/admin/assets/upload
     *
     * Accepts the file as multipart/form-data, uploads it to S3 server-side,
     * creates the asset record, and dispatches the processing job.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'                  => ['required', 'file', 'mimetypes:video/mp4,image/gif,image/png,image/jpeg,image/webp'],
            'name'                  => ['required', 'string', 'max:200'],
            'file_type'             => ['required', 'in:VIDEO,GIF,PHOTO'],
            'loop_id'               => ['nullable', 'uuid', 'exists:media_loops,id'],
            'size_bytes'            => ['nullable', 'integer', 'min:1'],
            'duration_secs'         => ['nullable', 'integer', 'min:1'],
            'geo_campaign'          => ['nullable', 'string', 'max:120'],
            'campaign_name'         => ['nullable', 'string', 'max:200'],
            'max_plays_per_hour'    => ['nullable', 'integer', 'min:1'],
            'max_daily_plays'       => ['nullable', 'integer', 'min:1'],
            'play_spots_remaining'  => ['nullable', 'integer', 'min:0'],
            'assigned_devices'      => ['nullable'],
            'is_global'             => ['nullable'],
            'conflict_asset_ids'    => ['nullable'],
            'stretch_to_fit'        => ['nullable'],
        ]);

        $file = $request->file('file');
        $objectKey = $this->s3->buildObjectKey($file->getClientOriginalName());

        $this->s3->upload($objectKey, $file);

        $assignedDevices = $request->assigned_devices;
        if (is_string($assignedDevices)) {
            $assignedDevices = json_decode($assignedDevices, true);
        }

        $conflictIds = $request->conflict_asset_ids;
        if (is_string($conflictIds)) {
            $conflictIds = json_decode($conflictIds, true);
        }

        $asset = MediaAsset::create([
            'name'                  => $request->name,
            'file_path'             => $objectKey,
            'file_type'             => $request->file_type,
            'loop_id'               => $request->loop_id,
            'size_bytes'            => $request->size_bytes ?? $file->getSize(),
            'duration_secs'         => $request->duration_secs,
            'geo_campaign'          => $request->geo_campaign,
            'campaign_name'         => $request->campaign_name,
            'max_plays_per_hour'    => $request->max_plays_per_hour,
            'max_daily_plays'       => $request->max_daily_plays,
            'play_spots_remaining'  => $request->play_spots_remaining ?? 0,
            'assigned_devices'      => $assignedDevices,
            'is_global'             => filter_var($request->is_global, FILTER_VALIDATE_BOOLEAN),
            'campaign_start_date'   => $request->campaign_start_date,
            'campaign_end_date'     => $request->campaign_end_date,
            'playback_times'        => is_string($request->playback_times)
                                           ? json_decode($request->playback_times, true)
                                           : $request->playback_times,
            'is_synced'             => false,
        ]);

        if (is_array($conflictIds) && count($conflictIds) > 0) {
            $asset->syncConflicts($conflictIds);
        }

        // Default ON: stretch the media to the billboard's exact dimensions
        // unless the uploader explicitly opted out.
        $stretchToFit = $request->has('stretch_to_fit')
            ? filter_var($request->stretch_to_fit, FILTER_VALIDATE_BOOLEAN)
            : true;

        AssetProcessingJob::dispatch($asset->id, $stretchToFit);

        $this->notifier->notifyScheduleChanged();

        return response()->json(new MediaAssetResource($asset->load(['loop', 'conflicts'])), 201);
    }
}
