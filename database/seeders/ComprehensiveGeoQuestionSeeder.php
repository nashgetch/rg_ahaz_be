<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GeoQuestion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class ComprehensiveGeoQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds - Merge 2000 existing + 5000 new = 7000 total questions
     */
    public function run(): void
    {
        $this->command->info('🌍 Comprehensive GeoSprint Question Seeder');
        $this->command->info('========================================');
        $this->command->info('📊 Target: 7000 questions (2000 existing + 5000 new)');
        
        // Step 1: Read existing 2000 questions from database
        $existingQuestions = $this->getExistingQuestions();
        
        // Step 2: Read new 5000 questions from JSON file
        $newQuestions = $this->readNewQuestions();
        
        // Step 3: Merge and randomize all 7000 questions
        $allQuestions = $this->mergeAndRandomizeQuestions($existingQuestions, $newQuestions);
        
        // Step 4: Clear database and insert randomized questions
        $this->insertRandomizedQuestions($allQuestions);
        
        // Step 5: Display final statistics
        $this->displayFinalStatistics();
    }

    /**
     * Get existing questions from database
     */
    private function getExistingQuestions(): array
    {
        $this->command->info('📖 Reading existing questions from database...');
        
        $existingCount = GeoQuestion::count();
        $this->command->info("Found {$existingCount} existing questions in database");
        
        if ($existingCount === 0) {
            $this->command->warn('⚠️  No existing questions found in database!');
            return [];
        }
        
        // Get all existing questions and convert to seeder format
        $questions = GeoQuestion::all()->map(function ($question) {
            return [
                'question_id' => $question->question_id,
                'question' => $question->question,
                'options' => $question->options, // Already an array from cast
                'correct_answer' => $question->correct_answer,
                'category' => $question->category,
                'difficulty' => $question->difficulty,
                'explanation' => $question->explanation,
                'points' => $question->points,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
                'source' => 'existing_db'
            ];
        })->toArray();
        
        $this->command->info("✅ Loaded {$existingCount} existing questions");
        return $questions;
    }

    /**
     * Read new questions from JSON file
     */
    private function readNewQuestions(): array
    {
        $this->command->info('📄 Reading new questions from JSON file...');
        
        $jsonPath = storage_path('app/trivia_5000_questions.json');
        
        if (!File::exists($jsonPath)) {
            $this->command->error("❌ JSON file not found at: {$jsonPath}");
            $this->command->info("Please ensure the file is placed at: backend/storage/app/trivia_5000_questions.json");
            throw new \Exception("JSON file not found");
        }
        
        $this->command->info("📍 Reading from: {$jsonPath}");
        
        try {
            $jsonContent = File::get($jsonPath);
            $questionsData = json_decode($jsonContent, true);
            
            if (!$questionsData || !is_array($questionsData)) {
                throw new \Exception("Invalid JSON format");
            }
            
            $this->command->info("Found " . count($questionsData) . " questions in JSON file");
            
            // Validate and prepare questions
            $validQuestions = [];
            $invalidCount = 0;
            
            foreach ($questionsData as $index => $questionData) {
                if ($this->isValidQuestion($questionData)) {
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
                        'source' => 'new_json'
                    ];
                } else {
                    $invalidCount++;
                }
            }
            
            if ($invalidCount > 0) {
                $this->command->warn("⚠️  Skipped {$invalidCount} invalid questions from JSON");
            }
            
            $this->command->info("✅ Loaded " . count($validQuestions) . " valid new questions");
            return $validQuestions;
            
        } catch (\Exception $e) {
            $this->command->error("❌ Error reading JSON file: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate individual question structure
     */
    private function isValidQuestion(array $questionData): bool
    {
        $requiredFields = ['id', 'question', 'options', 'correctAnswer', 'category', 'difficulty', 'points'];
        
        foreach ($requiredFields as $field) {
            if (!isset($questionData[$field])) {
                return false;
            }
        }

        return is_array($questionData['options']) &&
               count($questionData['options']) === 4 &&
               is_int($questionData['correctAnswer']) &&
               $questionData['correctAnswer'] >= 0 &&
               $questionData['correctAnswer'] <= 3 &&
               in_array($questionData['category'], ['ethiopia', 'africa', 'world']) &&
               in_array($questionData['difficulty'], ['easy', 'medium', 'hard']) &&
               is_int($questionData['points']) &&
               $questionData['points'] > 0;
    }

    /**
     * Merge and randomize all questions
     */
    private function mergeAndRandomizeQuestions(array $existingQuestions, array $newQuestions): array
    {
        $this->command->info('🔀 Merging and randomizing questions...');
        
        // Combine all questions
        $allQuestions = array_merge($existingQuestions, $newQuestions);
        $totalCount = count($allQuestions);
        
        $this->command->info("📊 Total questions to process: {$totalCount}");
        
        // Remove duplicate question IDs (prioritize existing questions)
        $uniqueQuestions = [];
        $questionIds = [];
        $duplicateCount = 0;
        
        foreach ($allQuestions as $question) {
            $id = $question['question_id'];
            if (!in_array($id, $questionIds)) {
                $questionIds[] = $id;
                $uniqueQuestions[] = $question;
            } else {
                $duplicateCount++;
            }
        }
        
        if ($duplicateCount > 0) {
            $this->command->warn("⚠️  Removed {$duplicateCount} duplicate questions");
        }
        
        $uniqueCount = count($uniqueQuestions);
        $this->command->info("✅ Unique questions: {$uniqueCount}");
        
        // Randomize the order completely
        $this->command->info('🎲 Randomizing insertion order...');
        shuffle($uniqueQuestions);
        
        // Additional randomization: create multiple random chunks and re-shuffle
        $chunks = array_chunk($uniqueQuestions, 500);
        shuffle($chunks);
        $randomizedQuestions = [];
        
        foreach ($chunks as $chunk) {
            shuffle($chunk);
            $randomizedQuestions = array_merge($randomizedQuestions, $chunk);
        }
        
        // Final shuffle to ensure maximum randomization
        shuffle($randomizedQuestions);
        
        $this->command->info("🔀 Questions randomized for insertion");
        
        return $randomizedQuestions;
    }

    /**
     * Insert randomized questions into database
     */
    private function insertRandomizedQuestions(array $questions): void
    {
        $this->command->info('🗑️  Clearing existing questions...');
        
        DB::beginTransaction();
        
        try {
            // Clear existing questions
            GeoQuestion::truncate();
            
            $this->command->info('📥 Inserting randomized questions...');
            
            // Insert in chunks for better performance
            $chunks = array_chunk($questions, 100);
            $progressBar = $this->command->output->createProgressBar(count($chunks));
            
            foreach ($chunks as $chunk) {
                // Remove the 'source' field before insertion
                $cleanChunk = array_map(function ($question) {
                    unset($question['source']);
                    return $question;
                }, $chunk);
                
                GeoQuestion::insert($cleanChunk);
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->command->newLine();
            
            DB::commit();
            
            $insertedCount = count($questions);
            $this->command->info("✅ Successfully inserted {$insertedCount} randomized questions!");
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->command->error("❌ Error during insertion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Display final statistics
     */
    private function displayFinalStatistics(): void
    {
        $this->command->info('📊 === FINAL STATISTICS ===');
        
        $stats = [
            'total' => GeoQuestion::count(),
            'ethiopia' => GeoQuestion::where('category', 'ethiopia')->count(),
            'africa' => GeoQuestion::where('category', 'africa')->count(),
            'world' => GeoQuestion::where('category', 'world')->count(),
            'easy' => GeoQuestion::where('difficulty', 'easy')->count(),
            'medium' => GeoQuestion::where('difficulty', 'medium')->count(),
            'hard' => GeoQuestion::where('difficulty', 'hard')->count(),
        ];
        
        $this->command->table(['Metric', 'Count', 'Percentage'], [
            ['Total Questions', $stats['total'], '100%'],
            ['', '', ''],
            ['Ethiopia', $stats['ethiopia'], round(($stats['ethiopia']/$stats['total'])*100, 1) . '%'],
            ['Africa', $stats['africa'], round(($stats['africa']/$stats['total'])*100, 1) . '%'],
            ['World', $stats['world'], round(($stats['world']/$stats['total'])*100, 1) . '%'],
            ['', '', ''],
            ['Easy', $stats['easy'], round(($stats['easy']/$stats['total'])*100, 1) . '%'],
            ['Medium', $stats['medium'], round(($stats['medium']/$stats['total'])*100, 1) . '%'],
            ['Hard', $stats['hard'], round(($stats['hard']/$stats['total'])*100, 1) . '%'],
        ]);
        
        $this->command->info('🎉 Database now contains ' . $stats['total'] . ' randomized geography questions!');
        $this->command->info('🎮 Ready for endless GeoSprint gameplay with no repetitions!');
    }
} 