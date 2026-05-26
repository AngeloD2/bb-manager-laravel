<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaAssetResource;
use App\Jobs\AssetProcessingJob;
use App\Models\MediaAsset;
use App\Services\S3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * AssetController
 *
 * Admin CRUD for media_assets plus the presigned-URL handshake:
 *
 *  GET    /api/v1/admin/assets
 *  POST   /api/v1/admin/assets
 *  PUT    /api/v1/admin/assets/{asset}
 *  DELETE /api/v1/admin/assets/{asset}
 *  POST   /api/v1/admin/assets/presigned-url   → generates S3 PUT URL
 *  POST   /api/v1/admin/assets/{asset}/confirm  → triggers AssetProcessingJob
 */
class AssetController extends Controller
{
    public function __construct(private readonly S3Service $s3) {}

    // ── Listing ───────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MediaAsset::with('folder')->latest();

        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->folder_id);
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

    // ── Create (metadata first, S3 upload second via presigned URL) ──────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:200'],
            'file_path'             => ['required', 'string'],   // S3 key from presigned-url step
            'file_type'             => ['required', 'in:VIDEO,GIF,PHOTO'],
            'folder_id'             => ['nullable', 'uuid', 'exists:media_folders,id'],
            'size_bytes'            => ['required', 'integer', 'min:1'],
            'duration_secs'         => ['nullable', 'integer', 'min:8', 'max:15'],
            'geo_campaign'          => ['nullable', 'string', 'max:120'],
            'campaign_name'         => ['nullable', 'string', 'max:200'],
            'max_plays_per_hour'    => ['nullable', 'integer', 'min:1'],
            'max_daily_plays'       => ['nullable', 'integer', 'min:1'],
            'play_tokens_remaining' => ['nullable', 'integer', 'min:0'],
        ]);

        $asset = MediaAsset::create(array_merge($data, ['is_synced' => false]));

        return response()->json(new MediaAssetResource($asset->load('folder')), 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, MediaAsset $asset): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:200'],
            'folder_id'             => ['nullable', 'uuid', 'exists:media_folders,id'],
            'geo_campaign'          => ['nullable', 'string', 'max:120'],
            'campaign_name'         => ['nullable', 'string', 'max:200'],
            'max_plays_per_hour'    => ['nullable', 'integer', 'min:1'],
            'max_daily_plays'       => ['nullable', 'integer', 'min:1'],
            'play_tokens_remaining' => ['sometimes', 'integer', 'min:0'],
        ]);

        $asset->update($data);

        return response()->json(new MediaAssetResource($asset->fresh('folder')));
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

        return response()->json(['message' => 'Asset deleted.']);
    }

    // ── Presigned URL handshake ───────────────────────────────────────────────

    /**
     * POST /api/v1/admin/assets/presigned-url
     *
     * Step 1 of the direct-upload flow:
     *  Client sends filename + mime type → server returns a presigned S3 PUT URL.
     *  Client PUTs the binary directly to S3, then calls /confirm.
     */
    public function presignedUrl(Request $request): JsonResponse
    {
        $request->validate([
            'filename'  => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'in:video/mp4,image/gif,image/png,image/jpeg,image/webp'],
        ]);

        $objectKey = $this->s3->buildObjectKey($request->filename);
        $presigned  = $this->s3->presignedPutUrl($objectKey, $request->mime_type);

        return response()->json(['data' => $presigned], 200);
    }

    /**
     * POST /api/v1/admin/assets/multipart-upload
     *
     * Fallback flow: Upload a file directly to the Laravel backend as multipart/form-data.
     * The server uploads the binary directly to S3 and dispatches the AssetProcessingJob.
     */
    public function multipartUpload(Request $request): JsonResponse
    {
        $request->validate([
            'file'                  => ['required', 'file', 'mimetypes:video/mp4,image/gif,image/png,image/jpeg,image/webp'],
            'name'                  => ['required', 'string', 'max:200'],
            'file_type'             => ['required', 'in:VIDEO,GIF,PHOTO'],
            'folder_id'             => ['nullable', 'uuid', 'exists:media_folders,id'],
            'geo_campaign'          => ['nullable', 'string', 'max:120'],
            'campaign_name'         => ['nullable', 'string', 'max:200'],
            'max_plays_per_hour'    => ['nullable', 'integer', 'min:1'],
            'max_daily_plays'       => ['nullable', 'integer', 'min:1'],
            'play_tokens_remaining' => ['nullable', 'integer', 'min:0'],
        ]);

        $file = $request->file('file');
        $objectKey = $this->s3->buildObjectKey($file->getClientOriginalName());

        // Upload to S3
        $this->s3->upload($objectKey, $file);

        // Create the asset record
        $asset = MediaAsset::create([
            'name'                  => $request->name,
            'file_path'             => $objectKey,
            'file_type'             => $request->file_type,
            'folder_id'             => $request->folder_id,
            'size_bytes'            => $file->getSize(),
            'duration_secs'         => $request->duration_secs ?? null, // Will be updated by FFprobe if not provided
            'geo_campaign'          => $request->geo_campaign,
            'campaign_name'         => $request->campaign_name,
            'max_plays_per_hour'    => $request->max_plays_per_hour,
            'max_daily_plays'       => $request->max_daily_plays,
            'play_tokens_remaining' => $request->play_tokens_remaining ?? 0,
            'is_synced'             => false,
        ]);

        // Dispatch background processing job
        AssetProcessingJob::dispatch($asset->id);

        return response()->json(new MediaAssetResource($asset->load('folder')), 201);
    }

    /**
     * POST /api/v1/admin/assets/{asset}/confirm
     *
     * Step 2: Client confirms the S3 upload is complete.
     * Dispatches AssetProcessingJob to run FFprobe and mark is_synced = true.
     */
    public function confirmUpload(MediaAsset $asset): JsonResponse
    {
        if ($asset->is_synced) {
            return response()->json(['message' => 'Asset already confirmed.']);
        }

        AssetProcessingJob::dispatch($asset->id);

        return response()->json([
            'message' => 'Upload confirmed. Asset is being processed.',
            'data'    => new MediaAssetResource($asset),
        ]);
    }
}
