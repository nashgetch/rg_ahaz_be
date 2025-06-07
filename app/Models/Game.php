<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'title',
        'description',
        'mechanic',
        'config',
        'token_cost',
        'max_score_reward',
        'enabled',
        'play_count',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * Get the rounds for the game.
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    /**
     * Get the leaderboard entries for the game.
     */
    public function leaderboards(): HasMany
    {
        return $this->hasMany(Leaderboard::class);
    }

    /**
     * Get completed rounds for the game.
     */
    public function completedRounds(): HasMany
    {
        return $this->rounds()->where('status', 'completed');
    }

    /**
     * Scope to only enabled games.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Get route key name for model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Increment play count.
     */
    public function incrementPlayCount(): void
    {
        $this->increment('play_count');
    }

    /**
     * Get game configuration value.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Calculate token reward based on score.
     */
    public function calculateReward(int $score): int
    {
        $maxReward = $this->max_score_reward;
        $maxPossibleScore = $this->getConfigValue('max_possible_score', 1000);
        
        // Calculate percentage-based reward
        $percentage = min($score / $maxPossibleScore, 1.0);
        
        return (int) round($maxReward * $percentage);
    }

    /**
     * Generate game seed for anti-cheat.
     */
    public function generateSeed(): string
    {
        return md5($this->slug . time() . random_int(1000, 9999));
    }
}
