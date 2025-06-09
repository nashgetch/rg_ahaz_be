<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinesGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create the mines game
        Game::create([
            'id' => 9,
            'slug' => 'mines',
            'title' => 'Mines',
            'description' => 'Risk-reward game: reveal safe tiles to increase multiplier, avoid bombs',
            'mechanic' => 'strategy',
            'config' => [
                'grid_size' => 5,
                'total_tiles' => 25,
                'bomb_count' => 3,
                'flag_cost' => 0.5,
                'base_multiplier' => 1.2,
                'multiplier_growth' => 1.2,
                'max_safe_tiles' => 22,
                'difficulty' => 'medium',
                'max_attempts_per_day' => 15,
                'is_multiplayer' => false,
                'icon' => '💣',
            ],
            'token_cost' => 1,
            'max_score_reward' => 2200,
            'enabled' => true,
            'play_count' => 0,
        ]);
    }

    public function test_can_start_mines_game()
    {
        $user = User::factory()->create([
            'tokens_balance' => 100
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/games/mines/start');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'round_id',
                    'board_hash',
                    'seed',
                    'grid_size',
                    'bomb_count',
                    'flag_available',
                    'flag_cost',
                    'user_tokens'
                ]
            ]);

        $this->assertEquals(99, $user->fresh()->tokens_balance);
    }

    public function test_can_reveal_safe_tile()
    {
        $user = User::factory()->create([
            'tokens_balance' => 100
        ]);

        // Start game
        $startResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/games/mines/start');

        $roundId = $startResponse->json('data.round_id');

        // Try to reveal a tile (we'll try multiple positions to find a safe one)
        $revealed = false;
        for ($row = 0; $row < 5 && !$revealed; $row++) {
            for ($col = 0; $col < 5 && !$revealed; $col++) {
                $response = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/v1/games/mines/{$roundId}/reveal", [
                        'row' => $row,
                        'col' => $col
                    ]);

                if ($response->status() === 200) {
                    $data = $response->json('data');
                    if ($data['safe']) {
                        $revealed = true;
                        $this->assertTrue($data['safe']);
                        $this->assertGreaterThan(0, $data['multiplier']);
                        $this->assertGreaterThan(0, $data['score']);
                        $this->assertEquals(1, $data['tiles_revealed']);
                    } else {
                        // Hit a bomb, that's also a valid test outcome
                        $this->assertFalse($data['safe']);
                        $this->assertTrue($data['game_over']);
                        $this->assertEquals(0, $data['score']);
                        $revealed = true;
                    }
                }
            }
        }

        $this->assertTrue($revealed, 'Should have been able to reveal at least one tile');
    }

    public function test_can_use_flag_power_up()
    {
        $user = User::factory()->create([
            'tokens_balance' => 100
        ]);

        // Start game
        $startResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/games/mines/start');

        $roundId = $startResponse->json('data.round_id');

        // Use flag
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/games/mines/{$roundId}/flag");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'flagged_position',
                    'row',
                    'col',
                    'user_tokens'
                ]
            ]);

        // Should have deducted 0.5 tokens
        $this->assertEquals(98.5, $user->fresh()->tokens_balance);
    }

    public function test_can_cash_out()
    {
        $user = User::factory()->create([
            'tokens_balance' => 100
        ]);

        // Start game
        $startResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/games/mines/start');

        $roundId = $startResponse->json('data.round_id');

        // Try to reveal a safe tile first
        $safeTileFound = false;
        for ($row = 0; $row < 5 && !$safeTileFound; $row++) {
            for ($col = 0; $col < 5 && !$safeTileFound; $col++) {
                $revealResponse = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/v1/games/mines/{$roundId}/reveal", [
                        'row' => $row,
                        'col' => $col
                    ]);

                if ($revealResponse->status() === 200) {
                    $data = $revealResponse->json('data');
                    if ($data['safe']) {
                        $safeTileFound = true;
                        
                        // Now cash out
                        $cashOutResponse = $this->actingAs($user, 'sanctum')
                            ->postJson("/api/v1/games/mines/{$roundId}/cashout");

                        $cashOutResponse->assertStatus(200)
                            ->assertJsonStructure([
                                'success',
                                'message',
                                'data' => [
                                    'score',
                                    'tokens_earned',
                                    'experience_gained',
                                    'tiles_revealed',
                                    'multiplier',
                                    'user_tokens',
                                    'seed',
                                    'bomb_positions'
                                ]
                            ]);

                        $cashOutData = $cashOutResponse->json('data');
                        $this->assertGreaterThan(0, $cashOutData['score']);
                        $this->assertGreaterThan(0, $cashOutData['tokens_earned']);
                        $this->assertEquals(1, $cashOutData['tiles_revealed']);
                    }
                }
            }
        }

        $this->assertTrue($safeTileFound, 'Should have found at least one safe tile to test cash out');
    }

    public function test_insufficient_tokens_prevents_game_start()
    {
        $user = User::factory()->create([
            'tokens_balance' => 0
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/games/mines/start');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Insufficient tokens'
            ]);
    }

    public function test_can_get_game_history()
    {
        $user = User::factory()->create([
            'tokens_balance' => 100
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/games/mines/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data'
            ]);

        // Should be empty initially
        $this->assertEmpty($response->json('data'));
    }
} 