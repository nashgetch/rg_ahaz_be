<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CrazyGameUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;
    public $gameState;
    public $actionType;
    public $playerId;
    public $leaderboard;
    public $notification;

    public function __construct(
        string $roomCode, 
        array $gameState, 
        string $actionType, 
        ?int $playerId = null, 
        ?array $leaderboard = null,
        ?array $notification = null
    ) {
        $this->roomCode = $roomCode;
        $this->gameState = $gameState;
        $this->actionType = $actionType;
        $this->playerId = $playerId;
        $this->leaderboard = $leaderboard;
        $this->notification = $notification;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crazy.room.' . $this->roomCode),
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
        $data = [
            'room_code' => $this->roomCode,
            'game_state' => $this->gameState,
            'action_type' => $this->actionType,
            'player_id' => $this->playerId,
            'leaderboard' => $this->leaderboard,
            'notification' => $this->notification,
            'timestamp' => now()->toISOString(),
        ];
        
        \Log::info('CrazyGameUpdated broadcasting data', [
            'channel' => 'crazy.room.' . $this->roomCode,
            'event' => 'game.updated',
            'action_type' => $this->actionType,
            'data_keys' => array_keys($data),
            'has_game_state' => !empty($this->gameState),
            'data_size' => strlen(json_encode($data))
        ]);
        
        return $data;
    }
} 