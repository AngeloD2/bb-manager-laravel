<?php

namespace App\Events;

use App\Models\Billboard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillboardCommand implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Billboard $billboard,
        public string $command,
        public ?array $payload = null
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('billboard.' . $this->billboard->id);
    }

    public function broadcastAs(): string
    {
        return 'billboard.command';
    }
}
