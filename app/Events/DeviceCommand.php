<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceCommand implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Device $device,
        public string $command,
        public ?array $payload = null
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('device.' . $this->device->id);
    }

    public function broadcastAs(): string
    {
        return 'device.command';
    }
}
