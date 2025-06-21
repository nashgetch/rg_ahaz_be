<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GeoQuestion;
use Illuminate\Support\Facades\File;

class JSONGeoQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing questions
        GeoQuestion::truncate();
        
        // Read the JSON file
        $jsonPath = base_path('../geography_trivia_questions_varied.json');
        
        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: {$jsonPath}");
            return;
        }
        
        $this->command->info("Reading questions from JSON file...");
        $jsonContent = File::get($jsonPath);
        $questionsData = json_decode($jsonContent, true);
        
        if (!$questionsData) {
            $this->command->error("Failed to parse JSON file");
            return;
        }
        
        $this->command->info("Found " . count($questionsData) . " questions in JSON file");
        
        // Transform and prepare questions for database
        $questions = [];
        foreach ($questionsData as $questionData) {
            $questions[] = [
                'question_id' => $questionData['id'],
                'question' => $questionData['question'],
                'options' => json_encode($questionData['options']),
                'correct_answer' => $questionData['correctAnswer'],
                'category' => $questionData['category'],
                'difficulty' => $questionData['difficulty'],
                'explanation' => $questionData['explanation'] ?? null,
                'points' => $questionData['points'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        // Insert questions in chunks for better performance
        $this->command->info("Inserting questions into database...");
        $chunks = array_chunk($questions, 100);
        
        foreach ($chunks as $index => $chunk) {
            GeoQuestion::insert($chunk);
            $this->command->info("Inserted chunk " . ($index + 1) . "/" . count($chunks));
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