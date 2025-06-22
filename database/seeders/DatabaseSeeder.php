<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            GameSeeder::class,
            // Uncomment to seed geography questions:
            // JSONGeoQuestionSeeder::class,
            // ComprehensiveGeoQuestionSeeder::class,
        ]);

        // Create test users
        User::factory(10)->create();

        // Create a test user with known credentials
        User::factory()->create([
            'name' => 'Test User',
            'phone' => '+251911123456',
            'tokens_balance' => 500,
            'locale' => 'en',
        ]);
    }
}
