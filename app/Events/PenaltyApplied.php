<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PenaltyApplied implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public int $targetId,
        public int $amount,
        public string $reason = 'penalty'
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("crazy.room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'penalty.applied';
    }

    public function broadcastWith(): array
    {
        return [
            'roomCode' => $this->roomCode,
            'targetId' => $this->targetId,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'timestamp' => now()->toISOString(),
        ];
    }
} 