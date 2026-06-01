<?php
// phpcs:ignoreFile

namespace App\Events;

use App\Models\Device;
use App\Models\MediaAsset;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PlaybackStarted
 *
 * Broadcast via Laravel Reverb (WebSocket) to notify the admin app
 * that a billboard device has started playing a media asset.
 *
 * Channel: device.{device_id}
 */
class PlaybackStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Device     $device,
        public readonly MediaAsset $asset,
        public readonly string     $startedAt
    ) {}

    public function broadcastOn(): array
    {
        // Public channel scoped to the specific billboard device (UUID makes it unguessable)
        return [new Channel("device.{$this->device->id}")];
    }

    public function broadcastAs(): string
    {
        return 'playback.started';
    }

    public function broadcastWith(): array
    {
        return [
            'asset_id'      => $this->asset->id,
            'asset_name'    => $this->asset->name,
            'file_type'     => $this->asset->file_type,
            'duration_secs' => $this->asset->duration_secs,
            'started_at'    => $this->startedAt,
        ];
    }
}
