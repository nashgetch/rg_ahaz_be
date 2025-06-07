<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePeriodStats extends Model
{
    protected $fillable = [
        'game_id',
        'period_type',
        'period_key',
        'total_players',
        'total_plays',
        'average_score',
        'highest_score',
        'lowest_score',
        'period_start',
        'period_end'
    ];

    protected $casts = [
        'total_players' => 'integer',
        'total_plays' => 'integer',
        'average_score' => 'decimal:2',
        'highest_score' => 'integer',
        'lowest_score' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime'
    ];

    /**
     * Get the game that owns the period stats.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
