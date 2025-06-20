<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShapeChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public string $shape
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("crazy.room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'shape.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'roomCode' => $this->roomCode,
            'shape' => $this->shape,
            'timestamp' => now()->toISOString(),
        ];
    }
} 