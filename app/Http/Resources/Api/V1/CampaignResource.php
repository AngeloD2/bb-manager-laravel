<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'starts_on'  => $this->starts_on?->format('Y-m-d'),
            'ends_on'    => $this->ends_on?->format('Y-m-d'),
            'loops_count' => $this->whenCounted('loops'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
