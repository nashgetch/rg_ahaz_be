<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Game;
use App\Services\BadgeService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BadgeServiceTest extends TestCase
{
    use RefreshDatabase;
    
    private BadgeService $badgeService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->badgeService = new BadgeService();
        
        // Set up test config
        Config::set('badges.tiers', [
            'DEMIGOD' => [
                'label' => 'DemiGod',
                'icon' => 'demigod',
                'rank_from' => 1,
                'rank_to' => 1,
                'scope' => 'global'
            ],
            'NIGUS' => [
                'label' => 'Nigus',
                'icon' => 'crown',
                'rank_from' => 1,
                'rank_to' => 1,
                'scope' => 'game'
            ],
            'RAS' => [
                'label' => 'Ras',
                'icon' => 'key',
                'rank_from' => 2,
                'rank_to' => 6,
                'scope' => 'game'
            ],
            'FITAWRARI' => [
                'label' => 'Fitawrari',
                'icon' => 'sword',
                'rank_from' => 7,
                'rank_to' => 21,
                'scope' => 'game'
            ],
        ]);
        
        Config::set('badges.precedence', [
            'DEMIGOD',
            'NIGUS',
            'RAS',
            'FITAWRARI'
        ]);
        
        // Mock Redis for testing
        Redis::shouldReceive('zrevrank')->andReturn(false)->byDefault();
        Redis::shouldReceive('zadd')->andReturn(1)->byDefault();
        Redis::shouldReceive('flushall')->andReturn(true)->byDefault();
    }

    public function test_badge_configuration_is_loaded_correctly()
    {
        $tiers = Config::get('badges.tiers');
        $precedence = Config::get('badges.precedence');
        
        $this->assertArrayHasKey('DEMIGOD', $tiers);
        $this->assertArrayHasKey('NIGUS', $tiers);
        $this->assertArrayHasKey('RAS', $tiers);
        $this->assertArrayHasKey('FITAWRARI', $tiers);
        
        $this->assertEquals(['DEMIGOD', 'NIGUS', 'RAS', 'FITAWRARI'], $precedence);
    }

    public function test_returns_null_when_user_has_no_badges()
    {
        $user = User::factory()->create();
        
        // Mock Redis to return false (no rank) for all queries
        Redis::shouldReceive('zrevrank')->andReturn(false);
        
        $badge = $this->badgeService->getUserBadge($user);
        
        $this->assertNull($badge);
    }

    public function test_returns_demigod_badge_for_global_rank_1()
    {
        $user = User::factory()->create();
        
        // Mock Redis to return rank 1 (0-indexed) for global leaderboard
        Redis::shouldReceive('zrevrank')
            ->with('leaderboard:global', $user->id)
            ->andReturn(0);
        
        $badge = $this->badgeService->getUserBadge($user);
        
        $this->assertEquals([
            'code' => 'DEMIGOD',
            'label' => 'DemiGod',
            'icon' => 'demigod',
            'scope' => 'global'
        ], $badge);
    }

    public function test_badge_service_exists()
    {
        $this->assertInstanceOf(BadgeService::class, $this->badgeService);
    }
} 