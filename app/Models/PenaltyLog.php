<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'violation_type',
        'description',
        'penalty_points',
        'token_penalty',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'token_penalty' => 'decimal:2',
    ];

    // Violation types constants
    const VIOLATION_ABANDONMENT = 'abandonment';
    const VIOLATION_CHEATING = 'cheating';
    const VIOLATION_MULTIPLE_GAMES = 'multiple_games';
    const VIOLATION_TOKEN_MANIPULATION = 'token_manipulation';
    const VIOLATION_COLLUSION = 'collusion';

    // Penalty points for different violations
    const PENALTY_POINTS = [
        self::VIOLATION_ABANDONMENT => 10,
        self::VIOLATION_CHEATING => 50,
        self::VIOLATION_MULTIPLE_GAMES => 15,
        self::VIOLATION_TOKEN_MANIPULATION => 100,
        self::VIOLATION_COLLUSION => 75,
    ];

    /**
     * Get the user that owns the penalty log.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a penalty log entry
     */
    public static function createPenalty(
        int $userId,
        string $violationType,
        string $description,
        array $metadata = [],
        ?float $tokenPenalty = null
    ): self {
        $penaltyPoints = self::PENALTY_POINTS[$violationType] ?? 10;
        
        return self::create([
            'user_id' => $userId,
            'violation_type' => $violationType,
            'description' => $description,
            'penalty_points' => $penaltyPoints,
            'token_penalty' => $tokenPenalty ?? 0,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get penalty summary for a user
     */
    public static function getUserPenaltySummary(int $userId): array
    {
        $penalties = self::where('user_id', $userId)
            ->selectRaw('violation_type, COUNT(*) as count, SUM(penalty_points) as total_points, SUM(token_penalty) as total_tokens')
            ->groupBy('violation_type')
            ->get();

        $summary = [
            'total_violations' => $penalties->sum('count'),
            'total_penalty_points' => $penalties->sum('total_points'),
            'total_token_penalties' => $penalties->sum('total_tokens'),
            'violations_by_type' => []
        ];

        foreach ($penalties as $penalty) {
            $summary['violations_by_type'][$penalty->violation_type] = [
                'count' => $penalty->count,
                'penalty_points' => $penalty->total_points,
                'token_penalties' => $penalty->total_tokens,
            ];
        }

        return $summary;
    }

    /**
     * Check if user should be suspended based on penalty points
     */
    public static function shouldSuspendUser(int $userId): bool
    {
        $totalPoints = self::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(30)) // Only count penalties from last 30 days
            ->sum('penalty_points');

        return $totalPoints >= 100; // Suspend if 100+ penalty points in 30 days
    }

    /**
     * Get suspension duration in hours based on penalty points
     */
    public static function getSuspensionDuration(int $penaltyPoints): int
    {
        if ($penaltyPoints >= 200) return 168; // 7 days
        if ($penaltyPoints >= 150) return 72;  // 3 days
        if ($penaltyPoints >= 100) return 24;  // 1 day
        return 0;
    }
}
