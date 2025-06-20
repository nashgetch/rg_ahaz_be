<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TurnAdvanced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public int $nextPlayerId,
        public bool $direction
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("crazy.room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'turn.advanced';
    }

    public function broadcastWith(): array
    {
        return [
            'roomCode' => $this->roomCode,
            'nextPlayerId' => $this->nextPlayerId,
            'direction' => $this->direction,
            'timestamp' => now()->toISOString(),
        ];
    }
} 