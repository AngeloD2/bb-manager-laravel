<?php

namespace App\Events;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\TimelineOverride;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * OverrideDispatched
 *
 * Broadcast via Laravel Reverb (WebSocket) to instantly notify a billboard
 * device that a Play Next override has been queued, rather than waiting for
 * the next polling cycle.
 *
 * Channel: private-device.{device_id}
 */
class OverrideDispatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly TimelineOverride $override,
        public readonly MediaAsset       $asset,
        public readonly Device           $device
    ) {}

    public function broadcastOn(): array
    {
        // Private channel scoped to the specific billboard device
        return [new PrivateChannel("device.{$this->device->id}")];
    }

    public function broadcastAs(): string
    {
        return 'override.dispatched';
    }

    public function broadcastWith(): array
    {
        return [
            'override_id'   => $this->override->id,
            'asset_id'      => $this->asset->id,
            'asset_name'    => $this->asset->name,
            'file_type'     => $this->asset->file_type,
            'duration_secs' => $this->asset->duration_secs,
            'delivery_url'  => $this->asset->deliveryUrl(600),  // 10-min URL for immediate play
        ];
    }
}
