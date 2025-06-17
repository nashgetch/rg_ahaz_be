<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultiplayerParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'status',
        'is_host',
        'score',
        'game_progress',
        'joined_at',
        'ready_at',
        'finished_at',
        'final_rank'
    ];

    protected $casts = [
        'game_progress' => 'array',
        'is_host' => 'boolean',
        'joined_at' => 'datetime',
        'ready_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // Relationships
    public function room(): BelongsTo
    {
        return $this->belongsTo(MultiplayerRoom::class, 'room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeHosts($query)
    {
        return $query->where('is_host', true);
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    public function scopeFinished($query)
    {
        return $query->where('status', 'finished');
    }

    // Methods
    public function markReady(): void
    {
        $this->update([
            'status' => 'ready',
            'ready_at' => now(),
        ]);
    }

    public function markFinished(?int $score = null): void
    {
        $this->update([
            'status' => 'finished',
            'score' => $score ?? $this->score,
            'finished_at' => now(),
        ]);
    }

    public function updateProgress(array $progress): void
    {
        $this->update([
            'game_progress' => array_merge($this->game_progress ?? [], $progress)
        ]);
    }

    public function isHost(): bool
    {
        return $this->is_host;
    }

    public function canStartGame(): bool
    {
        return $this->is_host && $this->room->current_players >= 2;
    }
}
