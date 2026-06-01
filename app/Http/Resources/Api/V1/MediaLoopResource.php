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
            'parent_loop_id' => $this->parent_loop_id,
            'is_fallback'      => $this->is_fallback,
            'is_global'        => $this->is_global,
            'max_daily_spots' => $this->max_daily_spots,
            'assigned_devices' => $this->assigned_devices,
            'time_blocks'      => $this->time_blocks,
            'assets_count'     => $this->whenCounted('assets'),
            'spots_spent_today' => $this->resource->spotsSpentToday(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
