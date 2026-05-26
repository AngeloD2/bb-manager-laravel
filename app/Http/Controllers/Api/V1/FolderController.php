<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MediaFolderResource;
use App\Models\MediaFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * FolderController
 *
 * Admin CRUD for media_folders:
 *  GET    /api/v1/admin/folders
 *  POST   /api/v1/admin/folders
 *  PUT    /api/v1/admin/folders/{folder}
 *  DELETE /api/v1/admin/folders/{folder}
 */
class FolderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $folders = MediaFolder::withCount('assets')
            ->orderBy('is_fallback')
            ->orderBy('name')
            ->get();

        return MediaFolderResource::collection($folders);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:120'],
            'parent_folder_id' => ['nullable', 'uuid', 'exists:media_folders,id'],
            'is_fallback'      => ['boolean'],
            'max_daily_tokens' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ]);

        $folder = MediaFolder::create($data);

        return response()->json(new MediaFolderResource($folder), 201);
    }

    public function update(Request $request, MediaFolder $folder): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:120'],
            'parent_folder_id' => ['nullable', 'uuid', Rule::exists('media_folders', 'id')->whereNot('id', $folder->id)],
            'is_fallback'      => ['sometimes', 'boolean'],
            'max_daily_tokens' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ]);

        $folder->update($data);

        return response()->json(new MediaFolderResource($folder));
    }

    public function destroy(MediaFolder $folder): JsonResponse
    {
        // Null-out child asset references before soft-deleting the folder.
        // The FK nullOnDelete trigger only fires on hard DELETE, not soft deletes.
        \App\Models\MediaAsset::where('folder_id', $folder->id)
            ->update(['folder_id' => null]);

        $folder->delete();

        return response()->json(['message' => 'Folder deleted.'], 200);
    }
}
