<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SecureShareLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->label,
            'folder_id'  => $this->folder_id,
            'asset_id'   => $this->asset_id,
            'share_url'  => $this->shareUrl(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_one_time'=> $this->is_one_time,
            'is_expired' => $this->is_expired || $this->expires_at?->isPast(),
            'used_count' => $this->used_count,
            'created_at' => $this->created_at?->toIso8601String(),
            // PIN is returned ONLY at creation time, never after — omitted here.
        ];
    }
}
