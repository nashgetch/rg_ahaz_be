<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GeoQuestion;

class MassGeoQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Use this seeder to add your 2000+ questions to the database.
     * Replace the $questions array with your generated questions.
     */
    public function run(): void
    {
        // Example format for adding questions in bulk
        // Replace this array with your 2000+ generated questions
        $questions = [
            /*
            [
                'question_id' => 'eth-100',
                'question' => 'Your question here?',
                'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                'correct_answer' => 0, // Index of correct answer (0-3)
                'category' => 'ethiopia', // ethiopia, africa, or world
                'difficulty' => 'easy', // easy, medium, or hard
                'explanation' => 'Your explanation here.',
                'points' => 100, // 100 for easy, 120 for medium, 140 for hard
                'is_active' => true,
            ],
            // Add more questions here...
            */
        ];

        // Insert questions in chunks for better performance
        $chunks = array_chunk($questions, 100);
        
        foreach ($chunks as $chunk) {
            GeoQuestion::insert($chunk);
        }

        $this->command->info('Successfully seeded ' . count($questions) . ' geography questions.');
        
        // Display statistics
        $stats = [
            'total' => GeoQuestion::count(),
            'ethiopia' => GeoQuestion::where('category', 'ethiopia')->count(),
            'africa' => GeoQuestion::where('category', 'africa')->count(),
            'world' => GeoQuestion::where('category', 'world')->count(),
            'easy' => GeoQuestion::where('difficulty', 'easy')->count(),
            'medium' => GeoQuestion::where('difficulty', 'medium')->count(),
            'hard' => GeoQuestion::where('difficulty', 'hard')->count(),
        ];
        
        $this->command->info("Database now contains:");
        $this->command->info("- Total questions: {$stats['total']}");
        $this->command->info("- Ethiopia: {$stats['ethiopia']}, Africa: {$stats['africa']}, World: {$stats['world']}");
        $this->command->info("- Easy: {$stats['easy']}, Medium: {$stats['medium']}, Hard: {$stats['hard']}");
    }
} 