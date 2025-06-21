<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GeoQuestion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class ProductionGeoQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds safely in production.
     */
    public function run(): void
    {
        // Safety check - prevent accidental double seeding
        $existingCount = GeoQuestion::count();
        if ($existingCount > 100) {
            $this->command->error("Database already contains {$existingCount} questions!");
            $this->command->error("This seeder is designed for initial setup only.");
            $this->command->info("If you want to update questions, use the UpdateGeoQuestionSeeder instead.");
            return;
        }

        // Look for JSON file in multiple possible locations
        $possiblePaths = [
            base_path('../geography_trivia_questions_varied.json'),
            base_path('geography_trivia_questions_varied.json'),
            storage_path('app/geography_trivia_questions_varied.json'),
        ];

        $jsonPath = null;
        foreach ($possiblePaths as $path) {
            if (File::exists($path)) {
                $jsonPath = $path;
                break;
            }
        }

        if (!$jsonPath) {
            $this->command->error("JSON file not found in any of these locations:");
            foreach ($possiblePaths as $path) {
                $this->command->error("  - {$path}");
            }
            $this->command->info("Please upload the JSON file to one of these locations.");
            return;
        }

        $this->command->info("Found JSON file at: {$jsonPath}");

        // Read and validate JSON
        $this->command->info("Reading questions from JSON file...");
        $jsonContent = File::get($jsonPath);
        $questionsData = json_decode($jsonContent, true);

        if (!$questionsData || !is_array($questionsData)) {
            $this->command->error("Failed to parse JSON file or file is not a valid array");
            return;
        }

        $totalQuestions = count($questionsData);
        $this->command->info("Found {$totalQuestions} questions in JSON file");

        if ($totalQuestions < 1000) {
            $this->command->warn("Warning: Only {$totalQuestions} questions found. Expected ~2000.");
            if (!$this->command->confirm('Continue anyway?')) {
                return;
            }
        }

        // Start database transaction for safety
        DB::beginTransaction();
        
        try {
            // Clear existing questions (if any)
            if ($existingCount > 0) {
                $this->command->info("Clearing {$existingCount} existing questions...");
                GeoQuestion::truncate();
            }

            // Validate and prepare questions
            $this->command->info("Validating and preparing questions...");
            $validQuestions = [];
            $invalidCount = 0;

            foreach ($questionsData as $index => $questionData) {
                // Validate required fields
                if (!isset($questionData['id']) || 
                    !isset($questionData['question']) || 
                    !isset($questionData['options']) || 
                    !isset($questionData['correctAnswer']) ||
                    !isset($questionData['category']) ||
                    !isset($questionData['difficulty']) ||
                    !isset($questionData['points'])) {
                    
                    $invalidCount++;
                    continue;
                }

                // Validate data types and values
                if (!is_array($questionData['options']) || 
                    count($questionData['options']) !== 4 ||
                    !is_int($questionData['correctAnswer']) ||
                    $questionData['correctAnswer'] < 0 || 
                    $questionData['correctAnswer'] > 3 ||
                    !in_array($questionData['category'], ['ethiopia', 'africa', 'world']) ||
                    !in_array($questionData['difficulty'], ['easy', 'medium', 'hard'])) {
                    
                    $invalidCount++;
                    continue;
                }

                $validQuestions[] = [
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

            if ($invalidCount > 0) {
                $this->command->warn("Skipped {$invalidCount} invalid questions");
            }

            $validCount = count($validQuestions);
            $this->command->info("Prepared {$validCount} valid questions for insertion");

            if ($validCount === 0) {
                throw new \Exception("No valid questions to insert!");
            }

            // Insert questions in chunks for better performance
            $this->command->info("Inserting questions into database...");
            $chunks = array_chunk($validQuestions, 100);
            
            foreach ($chunks as $index => $chunk) {
                GeoQuestion::insert($chunk);
                $this->command->info("Inserted chunk " . ($index + 1) . "/" . count($chunks));
            }

            // Commit transaction
            DB::commit();
            
            $this->command->info('Successfully seeded ' . $validCount . ' geography questions.');
            
            // Display final statistics
            $this->displayStatistics();

        } catch (\Exception $e) {
            // Rollback on error
            DB::rollback();
            $this->command->error("Error during seeding: " . $e->getMessage());
            $this->command->error("All changes have been rolled back.");
            throw $e;
        }
    }

    /**
     * Display final statistics
     */
    private function displayStatistics(): void
    {
        $stats = [
            'total' => GeoQuestion::count(),
            'ethiopia' => GeoQuestion::where('category', 'ethiopia')->count(),
            'africa' => GeoQuestion::where('category', 'africa')->count(),
            'world' => GeoQuestion::where('category', 'world')->count(),
            'easy' => GeoQuestion::where('difficulty', 'easy')->count(),
            'medium' => GeoQuestion::where('difficulty', 'medium')->count(),
            'hard' => GeoQuestion::where('difficulty', 'hard')->count(),
        ];
        
        $this->command->info("=== FINAL STATISTICS ===");
        $this->command->info("Total questions: {$stats['total']}");
        $this->command->info("By category:");
        $this->command->info("  - Ethiopia: {$stats['ethiopia']} (" . round(($stats['ethiopia']/$stats['total'])*100, 1) . "%)");
        $this->command->info("  - Africa: {$stats['africa']} (" . round(($stats['africa']/$stats['total'])*100, 1) . "%)");
        $this->command->info("  - World: {$stats['world']} (" . round(($stats['world']/$stats['total'])*100, 1) . "%)");
        $this->command->info("By difficulty:");
        $this->command->info("  - Easy: {$stats['easy']} (" . round(($stats['easy']/$stats['total'])*100, 1) . "%)");
        $this->command->info("  - Medium: {$stats['medium']} (" . round(($stats['medium']/$stats['total'])*100, 1) . "%)");
        $this->command->info("  - Hard: {$stats['hard']} (" . round(($stats['hard']/$stats['total'])*100, 1) . "%)");
    }
} 