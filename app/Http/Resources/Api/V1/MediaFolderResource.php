<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'parent_folder_id' => $this->parent_folder_id,
            'is_fallback'      => $this->is_fallback,
            'max_daily_tokens' => $this->max_daily_tokens,
            'assets_count'     => $this->whenCounted('assets'),
            'tokens_spent_today' => $this->whenLoaded('assets', fn () => null, null),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
