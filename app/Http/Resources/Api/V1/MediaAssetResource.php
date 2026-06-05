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
            'download_url'          => $this->deliveryUrl(),
            'file_type'             => $this->file_type,
            'loop_id'             => $this->loop_id,
            'size_bytes'            => $this->size_bytes,
            'duration_secs'         => $this->duration_secs,
            'geo_campaign'          => $this->geo_campaign,
            'campaign_name'         => $this->campaign_name,
            'is_synced'             => $this->is_synced,
            'sync_error'            => $this->sync_error,
            'is_global'             => $this->is_global,
            'max_plays_per_hour'    => $this->max_plays_per_hour,
            'max_daily_plays'       => $this->max_daily_plays,
            'play_spots_remaining'  => $this->play_spots_remaining,
            'campaign_start_date'   => $this->campaign_start_date?->format('Y-m-d'),
            'campaign_end_date'     => $this->campaign_end_date?->format('Y-m-d'),
            'playback_times'        => $this->playback_times ?? [],
            'assigned_devices'      => $this->assigned_devices,
            'loop'                => new MediaLoopResource($this->whenLoaded('loop')),
            'conflict_asset_ids'    => $this->whenLoaded('conflicts', fn () => $this->conflicts->pluck('id')->toArray()),
            'created_at'            => $this->created_at?->toIso8601String(),
            'updated_at'            => $this->updated_at?->toIso8601String(),
        ];
    }
}
