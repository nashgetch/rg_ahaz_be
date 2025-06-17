<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MultiplayerRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_code',
        'host_user_id',
        'game_id',
        'room_name',
        'description',
        'status',
        'max_players',
        'current_players',
        'is_private',
        'password',
        'game_config',
        'game_state',
        'started_at',
        'completed_at',
        'winner_user_id',
        'final_scores'
    ];

    protected $casts = [
        'game_config' => 'array',
        'game_state' => 'array',
        'final_scores' => 'array',
        'is_private' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($room) {
            if (empty($room->room_code)) {
                $room->room_code = static::generateUniqueRoomCode();
            }
        });
    }

    // Relationships
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MultiplayerParticipant::class, 'room_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(MultiplayerParticipant::class, 'room_id')->where('status', 'invited');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['waiting', 'starting', 'in_progress']);
    }

    public function scopePublic($query)
    {
        return $query->where('is_private', false);
    }

    public function scopeJoinable($query)
    {
        return $query->where('status', 'waiting')
                     ->whereRaw('current_players < max_players');
    }

    // Methods
    public static function generateUniqueRoomCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (static::where('room_code', $code)->exists());
        
        return $code;
    }

    public function canJoin(?User $user = null): bool
    {
        \Log::info('Checking if user can join room');
        \Log::info('Room status: ' . $this->status);
        \Log::info('Current players: ' . $this->current_players);
        \Log::info('Max players: ' . $this->max_players);
        if ($user) {
            \Log::info('User: ' . $user->name);
            \Log::info('User ID: ' . $user->id);
            \Log::info('User in room: ' . $this->participants()->where('user_id', $user->id)->exists());
        }
        
        if ($this->status !== 'waiting') {
            \Log::info('Room is not waiting');
            return false;
        }
        
        if ($this->current_players >= $this->max_players) {
            \Log::info('Room is full');
            return false;
        }
        
        if ($user && $this->participants()->where('user_id', $user->id)->exists()) {
            \Log::info('User already in room');
            return false;
        }
        
        return true;
    }

    public function addParticipant(User $user, bool $isHost = false): MultiplayerParticipant
    {
        $participant = $this->participants()->create([
            'user_id' => $user->id,
            'is_host' => $isHost,
            'status' => 'joined',
            'joined_at' => now(),
        ]);

        $this->increment('current_players');
        
        return $participant;
    }

    public function removeParticipant(User $user): bool
    {
        $participant = $this->participants()->where('user_id', $user->id)->first();
        
        if (!$participant) {
            return false;
        }

        $participant->delete();
        $this->decrement('current_players');
        
        // If host leaves, transfer to another participant or cancel room
        if ($participant->is_host && $this->current_players > 0) {
            $newHost = $this->participants()->where('is_host', false)->first();
            if ($newHost) {
                $newHost->update(['is_host' => true]);
                $this->update(['host_user_id' => $newHost->user_id]);
            }
        } elseif ($this->current_players <= 0) {
            $this->update(['status' => 'cancelled']);
        }
        
        return true;
    }

    public function startGame(): bool
    {
        if ($this->status !== 'waiting' || $this->current_players < 2) {
            return false;
        }

        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // Update all participants to playing status
        $this->participants()->update(['status' => 'playing']);

        return true;
    }

    public function completeGame(): void
    {
        $participants = $this->participants()
            ->orderBy('score', 'desc')
            ->orderBy('finished_at', 'asc')
            ->get();

        $finalScores = [];
        $rank = 1;
        
        foreach ($participants as $participant) {
            $participant->update(['final_rank' => $rank]);
            $finalScores[] = [
                'user_id' => $participant->user_id,
                'username' => $participant->user->name,
                'score' => $participant->score,
                'rank' => $rank
            ];
            $rank++;
        }

        $winner = $participants->first();
        
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'winner_user_id' => $winner->user_id,
            'final_scores' => $finalScores
        ]);
    }

    public function getShareUrl(): string
    {
        return config('app.frontend_url') . "/multiplayer/join/{$this->room_code}";
    }
}
