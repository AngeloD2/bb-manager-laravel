<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaAssetResource;
use App\Jobs\AssetProcessingJob;
use App\Models\MediaAsset;
use App\Services\DeviceNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'duration_secs'         => ['nullable', 'integer', 'min:1'],
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
        $assetData['duration_secs'] = $assetData['duration_secs'] ?? 10;
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
            'duration_secs'         => ['sometimes', 'integer', 'min:1'],
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

        // Video duration is intrinsic to the file (ffprobe-measured) and is
        // not editable; only still media (photo/GIF) has an adjustable dwell time.
        if ($asset->file_type === 'VIDEO') {
            unset($assetData['duration_secs']);
        }

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
            Storage::disk('s3')->delete($asset->file_path);
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
     * POST /api/v1/admin/assets/presign
     * Generates a presigned PUT URL for direct-to-S3 uploads.
     */
    public function presign(Request $request): JsonResponse
    {
        $request->validate([
            'original_name' => ['required', 'string'],
            'file_type'     => ['required', 'in:VIDEO,GIF,PHOTO'],
            'content_type'  => ['required', 'in:video/mp4,video/quicktime,image/gif,image/png,image/jpeg,image/webp'],
            'size_bytes'    => ['required', 'integer', 'max:5368709120'], // 5GB limit
        ]);

        $objectKey = $this->buildObjectKey($request->original_name);
        $expiresIn = 900; // 15 minutes

        $uploadData = Storage::disk('s3')->temporaryUploadUrl(
            $objectKey, 
            now()->addSeconds($expiresIn),
            ['ContentType' => $request->content_type]
        );

        $uploadUrl = is_array($uploadData) ? $uploadData['url'] : $uploadData;

        return response()->json([
            'object_key' => $objectKey,
            'upload_url' => $uploadUrl,
            'headers'    => is_array($uploadData) ? $uploadData['headers'] : ['Content-Type' => $request->content_type, 'Content-Length' => (string) $request->size_bytes],
            'expires_in' => $expiresIn,
        ]);
    }

    /**
     * POST /api/v1/admin/assets/confirm
     * Confirms that a presigned S3 upload finished, creates the asset record,
     * and triggers the background processing job.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'object_key'            => ['required', 'string'],
            'name'                  => ['required', 'string', 'max:200'],
            'file_type'             => ['required', 'in:VIDEO,GIF,PHOTO'],
            'loop_id'               => ['nullable', 'uuid', 'exists:media_loops,id'],
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

        if (!Storage::disk('s3')->exists($request->object_key)) {
            return response()->json(['message' => 'File not found in S3.'], 400);
        }

        $size = Storage::disk('s3')->size($request->object_key);
        
        if ($size === 0) {
            return response()->json(['message' => 'The uploaded file is 0 bytes on Cloudflare R2. The upload stream failed.'], 400);
        }

        if ($size > 5368709120) {
            Storage::disk('s3')->delete($request->object_key);
            return response()->json(['message' => 'File exceeds 5GB limit.'], 400);
        }

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
            'file_path'             => $request->object_key,
            'file_type'             => $request->file_type,
            'loop_id'               => $request->loop_id,
            'size_bytes'            => $size,
            'duration_secs'         => $request->duration_secs ?? 10,
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

        $stretchToFit = $request->has('stretch_to_fit')
            ? filter_var($request->stretch_to_fit, FILTER_VALIDATE_BOOLEAN)
            : true;

        AssetProcessingJob::dispatch($asset->id, $stretchToFit);

        $this->notifier->notifyScheduleChanged();

        return response()->json(new MediaAssetResource($asset->load(['loop', 'conflicts'])), 201);
    }

    /**
     * Build a deterministic, human-readable S3 key for a new asset.
     */
    private function buildObjectKey(string $filename): string
    {
        $ext  = pathinfo($filename, PATHINFO_EXTENSION);
        $slug = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $uuid = Str::uuid();

        return sprintf(
            'media/%s/%s/%s-%s.%s',
            now()->format('Y'),
            now()->format('m'),
            $uuid,
            $slug,
            strtolower($ext)
        );
    }
}
