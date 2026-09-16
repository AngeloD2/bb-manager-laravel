<?php
// phpcs:ignoreFile

namespace App\Events;

use App\Models\Billboard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PlaybackStopped
 *
 * Broadcast via Laravel Reverb (WebSocket) to notify the admin app
 * that a billboard has stopped playback (e.g. queue exhausted or no media scheduled).
 *
 * Channel: billboard.{billboard_id}
 */
class PlaybackStopped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Billboard $billboard,
        public readonly string    $stoppedAt,
        public readonly ?string   $reason = null
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("billboard.{$this->billboard->id}")];
    }

    public function broadcastAs(): string
    {
        return 'playback.stopped';
    }

    public function broadcastWith(): array
    {
        return [
            'billboard_id' => $this->billboard->id,
            'stopped_at'   => $this->stoppedAt,
            'reason'       => $this->reason,
        ];
    }
}
