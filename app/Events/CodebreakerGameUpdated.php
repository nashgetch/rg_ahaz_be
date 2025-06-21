<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CodebreakerGameUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;
    public $actionType;
    public $playerId;
    public $data;

    public function __construct(
        string $roomCode, 
        string $actionType, 
        ?int $playerId = null, 
        array $data = []
    ) {
        $this->roomCode = $roomCode;
        $this->actionType = $actionType;
        $this->playerId = $playerId;
        $this->data = $data;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('codebreaker.room.' . $this->roomCode),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'game.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'action_type' => $this->actionType,
            'player_id' => $this->playerId,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
        ];
    }
} 