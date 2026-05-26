<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'file_path'             => $this->file_path,
            'file_type'             => $this->file_type,
            'folder_id'             => $this->folder_id,
            'size_bytes'            => $this->size_bytes,
            'duration_secs'         => $this->duration_secs,
            'geo_campaign'          => $this->geo_campaign,
            'campaign_name'         => $this->campaign_name,
            'is_synced'             => $this->is_synced,
            'max_plays_per_hour'    => $this->max_plays_per_hour,
            'max_daily_plays'       => $this->max_daily_plays,
            'play_tokens_remaining' => $this->play_tokens_remaining,
            'folder'                => new MediaFolderResource($this->whenLoaded('folder')),
            'created_at'            => $this->created_at?->toIso8601String(),
            'updated_at'            => $this->updated_at?->toIso8601String(),
        ];
    }
}
