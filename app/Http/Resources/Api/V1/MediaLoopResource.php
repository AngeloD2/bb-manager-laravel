<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaLoopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'campaign_id'    => $this->campaign_id,
            'is_fallback'      => (bool) $this->is_fallback,
            'is_global'        => (bool) $this->is_global,
            'is_bundle'        => (bool) $this->is_bundle,
            'max_daily_spots' => $this->max_daily_spots,
            'assigned_billboards' => $this->assigned_billboards,
            'order_index'      => $this->order_index,
            'time_blocks'      => $this->time_blocks,
            'assets_count'     => $this->whenCounted('assets'),
            'spots_spent_today' => $this->resource->spotsSpentToday(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
