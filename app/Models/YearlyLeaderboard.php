<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YearlyLeaderboard extends Model
{
    protected $fillable = [
        'user_id',
        'game_id',
        'year',
        'best_score',
        'average_score',
        'total_score',
        'total_plays',
        'first_played_at',
        'last_played_at'
    ];

    protected $casts = [
        'year' => 'integer',
        'best_score' => 'integer',
        'average_score' => 'decimal:2',
        'total_score' => 'integer',
        'total_plays' => 'integer',
        'first_played_at' => 'datetime',
        'last_played_at' => 'datetime'
    ];

    /**
     * Get the user that owns the yearly leaderboard entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the game that owns the yearly leaderboard entry.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
