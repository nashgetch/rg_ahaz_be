<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Round extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'seed',
        'cost_tokens',
        'score',
        'moves',
        'duration_ms',
        'completion_time',
        'reward_tokens',
        'experience_gained',
        'game_data',
        'score_hash',
        'status',
        'is_timeout',
        'is_flagged',
        'flag_reason',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'game_data' => 'array',
        'moves' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_timeout' => 'boolean',
        'is_flagged' => 'boolean',
        'score' => 'integer',
        'cost_tokens' => 'integer',
        'reward_tokens' => 'integer',
        'experience_gained' => 'integer',
        'duration_ms' => 'integer',
        'completion_time' => 'integer'
    ];

    /**
     * Get the game that owns the round.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Get the user that owns the round.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
