<?php

namespace App\Events;

use App\Models\MultiplayerRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MultiplayerRoomUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $room;
    public $actionType;
    public $playerId;

    public function __construct(MultiplayerRoom $room, string $actionType, ?int $playerId = null)
    {
        $this->room = $room->load(['participants.user:id,name', 'game:id,name,slug']);
        $this->actionType = $actionType;
        $this->playerId = $playerId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('multiplayer.room.' . $this->room->room_code),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'room.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room' => [
                'id' => $this->room->id,
                'room_code' => $this->room->room_code,
                'status' => $this->room->status,
                'current_players' => $this->room->participants->count(),
                'max_players' => $this->room->max_players,
                'game' => $this->room->game,
                'participants' => $this->room->participants->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'user_id' => $p->user_id,
                        'username' => $p->user->name,
                        'status' => $p->status,
                        'bet_amount' => $p->bet_amount,
                        'score' => $p->score,
                    ];
                }),
                'updated_at' => $this->room->updated_at,
            ],
            'action_type' => $this->actionType,
            'player_id' => $this->playerId,
            'timestamp' => now()->toISOString(),
        ];
    }
} 