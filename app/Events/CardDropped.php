<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CardDropped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public int $playerId,
        public array $card,
        public ?string $newSuit = null
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("crazy.room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'card.dropped';
    }

    public function broadcastWith(): array
    {
        return [
            'roomCode' => $this->roomCode,
            'playerId' => $this->playerId,
            'card' => $this->card,
            'newSuit' => $this->newSuit,
            'timestamp' => now()->toISOString(),
        ];
    }
} 