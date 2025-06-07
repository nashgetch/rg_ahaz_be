<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'avatar',
        'email',
        'phone',
        'password',
        'locale',
        'tokens_balance',
        'level',
        'experience',
        'last_login_at',
        'daily_bonus_claimed_at',
        'preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'daily_bonus_claimed_at' => 'datetime',
        'preferences' => 'array',
    ];

    /**
     * Get the rounds for the user.
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    /**
     * Get the transactions for the user.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the leaderboard entries for the user.
     */
    public function leaderboards(): HasMany
    {
        return $this->hasMany(Leaderboard::class);
    }

    /**
     * Check if user can afford a game cost.
     */
    public function canAfford(int $cost): bool
    {
        return $this->tokens_balance >= $cost;
    }

    /**
     * Spend tokens and create transaction.
     */
    public function spendTokens(int $amount, string $description, array $meta = []): Transaction
    {
        $this->decrement('tokens_balance', $amount);

        return $this->transactions()->create([
            'amount' => -$amount,
            'type' => 'powerup',
            'description' => $description,
            'meta' => $meta,
            'status' => 'completed',
        ]);
    }

    /**
     * Award tokens and create transaction.
     */
    public function awardTokens(int $amount, string $type, string $description, array $meta = []): Transaction
    {
        $this->increment('tokens_balance', $amount);

        return $this->transactions()->create([
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'meta' => $meta,
            'status' => 'completed',
        ]);
    }

    /**
     * Check if user can claim daily bonus.
     */
    public function canClaimDailyBonus(): bool
    {
        if (!$this->daily_bonus_claimed_at) {
            return true;
        }

        return $this->daily_bonus_claimed_at->isYesterday() || 
               $this->daily_bonus_claimed_at->lt(now()->startOfDay());
    }

    /**
     * Claim daily bonus tokens.
     */
    public function claimDailyBonus(): ?Transaction
    {
        if (!$this->canClaimDailyBonus()) {
            return null;
        }

        $this->update(['daily_bonus_claimed_at' => now()]);
        
        $bonusAmount = config('games.daily_bonus_tokens', 20);
        
        return $this->awardTokens(
            $bonusAmount,
            'daily_bonus',
            'Daily login bonus',
            ['bonus_date' => now()->toDateString()]
        );
    }

    /**
     * Calculate experience needed for next level.
     */
    public function experienceToNextLevel(): int
    {
        $currentLevel = $this->level ?? 1;
        $currentExperience = $this->experience ?? 0;
        
        // Simple formula: level * 100 XP needed for next level
        $experienceForNextLevel = $currentLevel * 100;
        
        return max(0, $experienceForNextLevel - $currentExperience);
    }

    /**
     * Add experience and handle level ups.
     */
    public function addExperience(int $amount): bool
    {
        $currentLevel = $this->level ?? 1;
        $currentExperience = $this->experience ?? 0;
        
        $newExperience = $currentExperience + $amount;
        $experienceForNextLevel = $currentLevel * 100;
        
        $leveledUp = false;
        
        // Check for level up
        while ($newExperience >= $experienceForNextLevel) {
            $currentLevel++;
            $newExperience -= $experienceForNextLevel;
            $experienceForNextLevel = $currentLevel * 100;
            $leveledUp = true;
        }
        
        $this->update([
            'level' => $currentLevel,
            'experience' => $newExperience
        ]);
        
        return $leveledUp;
    }
}
