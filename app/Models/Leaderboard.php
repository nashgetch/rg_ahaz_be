<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leaderboard extends Model
{
    protected $fillable = [
        'user_id',
        'game_id',
        'best_score',
        'average_score',
        'total_score',
        'total_plays',
        'period_type',
        'period_key',
        'last_played_at'
    ];

    protected $casts = [
        'best_score' => 'integer',
        'average_score' => 'decimal:2',
        'total_score' => 'integer',
        'total_plays' => 'integer',
        'last_played_at' => 'datetime'
    ];

    /**
     * Get the user that owns the leaderboard entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the game that owns the leaderboard entry.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Scope to get current period leaderboards
     */
    public function scopeCurrent($query)
    {
        return $query->where('period_type', 'current');
    }

    /**
     * Scope to get monthly leaderboards
     */
    public function scopeMonthly($query, ?string $monthKey = null)
    {
        $query = $query->where('period_type', 'monthly');
        
        if ($monthKey) {
            $query->where('period_key', $monthKey);
        }
        
        return $query;
    }

    /**
     * Scope to get yearly leaderboards
     */
    public function scopeYearly($query, ?string $yearKey = null)
    {
        $query = $query->where('period_type', 'yearly');
        
        if ($yearKey) {
            $query->where('period_key', $yearKey);
        }
        
        return $query;
    }
}
