<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QeregnAnnounced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public int $playerId
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("crazy.room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'qeregn.announced';
    }

    public function broadcastWith(): array
    {
        return [
            'roomCode' => $this->roomCode,
            'playerId' => $this->playerId,
            'timestamp' => now()->toISOString(),
        ];
    }
} 