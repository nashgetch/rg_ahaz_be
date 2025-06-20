<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LobbyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $updateType,
        public array $data = []
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('presence.global-lobby');
    }

    public function broadcastAs(): string
    {
        return 'lobby.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'updateType' => $this->updateType,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
        ];
    }
} 