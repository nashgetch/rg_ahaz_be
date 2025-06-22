<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GeoQuestion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class ComprehensiveGeoQuestionSeeder extends Seeder
{
    /**
     * Safe info output that works in all contexts
     */
    private function info(string $message): void
    {
        if (isset($this->command) && method_exists($this->command, 'info')) {
            $this->command->info($message);
        } else {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Safe warning output that works in all contexts
     */
    private function warn(string $message): void
    {
        if (isset($this->command) && method_exists($this->command, 'warn')) {
            $this->command->warn($message);
        } else {
            echo "WARNING: " . $message . PHP_EOL;
        }
    }

    /**
     * Safe error output that works in all contexts
     */
    private function error(string $message): void
    {
        if (isset($this->command) && method_exists($this->command, 'error')) {
            $this->command->error($message);
        } else {
            echo "ERROR: " . $message . PHP_EOL;
        }
    }

    /**
     * Safe table output that works in all contexts
     */
    private function table(array $headers, array $data): void
    {
        if (isset($this->command) && method_exists($this->command, 'table')) {
            $this->command->table($headers, $data);
        } else {
            // Simple text table fallback
            echo implode(' | ', $headers) . PHP_EOL;
            echo str_repeat('-', 50) . PHP_EOL;
            foreach ($data as $row) {
                echo implode(' | ', $row) . PHP_EOL;
            }
        }
    }
    /**
     * Run the database seeds - Insert JSON data as-is with maximum randomization
     */
    public function run(): void
    {
        $this->info('🌍 Comprehensive Question Seeder (No Validation)');
        $this->info('===============================================');
        $this->info('📊 Target: Insert all JSON data as-is with maximum randomization');
        
        // Step 1: Read existing questions from database
        $existingQuestions = $this->getExistingQuestions();
        
        // Step 2: Read new questions from JSON file (no validation)
        $newQuestions = $this->readNewQuestions();
        
        // Step 3: Merge and apply MAXIMUM randomization
        $allQuestions = $this->mergeAndRandomizeQuestions($existingQuestions, $newQuestions);
        
        // Step 4: Clear database and insert in completely random order
        $this->insertRandomizedQuestions($allQuestions);
        
        // Step 5: Display final statistics
        $this->displayFinalStatistics();
    }

    /**
     * Get existing questions from database
     */
    private function getExistingQuestions(): array
    {
        $this->info('📖 Reading existing questions from database...');
        
        $existingCount = GeoQuestion::count();
        $this->info("Found {$existingCount} existing questions in database");
        
        if ($existingCount === 0) {
            $this->warn('⚠️  No existing questions found in database!');
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
        
        $this->info("✅ Loaded {$existingCount} existing questions");
        return $questions;
    }

    /**
     * Read new questions from JSON file
     */
    private function readNewQuestions(): array
    {
        $this->info('📄 Reading new questions from JSON file...');
        
        $jsonPath = storage_path('app/trivia_5000_questions.json');
        
        if (!File::exists($jsonPath)) {
            $this->error("❌ JSON file not found at: {$jsonPath}");
            $this->info("Please ensure the file is placed at: backend/storage/app/trivia_5000_questions.json");
            throw new \Exception("JSON file not found");
        }
        
        $this->info("📍 Reading from: {$jsonPath}");
        
        try {
            $jsonContent = File::get($jsonPath);
            $questionsData = json_decode($jsonContent, true);
            
            if (!$questionsData || !is_array($questionsData)) {
                throw new \Exception("Invalid JSON format");
            }
            
                        $this->info("Found " . count($questionsData) . " questions in JSON file");
            
            // Prepare questions without validation - insert as-is
            $newQuestions = [];
            
            foreach ($questionsData as $questionData) {
                 $newQuestions[] = [
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
             }
             
                         $this->info("✅ Loaded " . count($newQuestions) . " questions from JSON (no validation)");
            return $newQuestions;
            
        } catch (\Exception $e) {
            $this->error("❌ Error reading JSON file: " . $e->getMessage());
            throw $e;
        }
    }



    /**
     * Merge and randomize all questions
     */
    private function mergeAndRandomizeQuestions(array $existingQuestions, array $newQuestions): array
    {
        $this->info('🔀 Merging and randomizing questions...');
        
        // Combine all questions
        $allQuestions = array_merge($existingQuestions, $newQuestions);
        $totalCount = count($allQuestions);
        
        $this->info("📊 Total questions to process: {$totalCount}");
        
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
            $this->warn("⚠️  Removed {$duplicateCount} duplicate questions");
        }
        
        $uniqueCount = count($uniqueQuestions);
        $this->info("✅ Unique questions: {$uniqueCount}");
        
        // MAXIMUM RANDOMIZATION - multiple shuffle stages
        $this->info('🎲 Applying maximum randomization to insertion order...');
        
        // Stage 1: Initial shuffle
        shuffle($uniqueQuestions);
        
        // Stage 2: Random chunk method (varying chunk sizes)
        $chunkSizes = [50, 100, 200, 300, 500];
        $randomChunkSize = $chunkSizes[array_rand($chunkSizes)];
        $chunks = array_chunk($uniqueQuestions, $randomChunkSize);
        shuffle($chunks);
        
        $randomizedQuestions = [];
        foreach ($chunks as $chunk) {
            shuffle($chunk); // Shuffle within each chunk
            $randomizedQuestions = array_merge($randomizedQuestions, $chunk);
        }
        
        // Stage 3: Second full shuffle
        shuffle($randomizedQuestions);
        
        // Stage 4: Random swap method for extra randomization
        $totalQuestions = count($randomizedQuestions);
        for ($i = 0; $i < $totalQuestions * 2; $i++) {
            $pos1 = rand(0, $totalQuestions - 1);
            $pos2 = rand(0, $totalQuestions - 1);
            
            // Swap elements at random positions
            $temp = $randomizedQuestions[$pos1];
            $randomizedQuestions[$pos1] = $randomizedQuestions[$pos2];
            $randomizedQuestions[$pos2] = $temp;
        }
        
        // Stage 5: Final shuffle to guarantee maximum randomness
        shuffle($randomizedQuestions);
        
        $this->info("🔀 Maximum randomization applied - questions ready for insertion");
        $this->info("🎯 Final order is completely random and unpredictable");
        
        return $randomizedQuestions;
    }

    /**
     * Insert randomized questions into database
     */
    private function insertRandomizedQuestions(array $questions): void
    {
        $this->info('🗑️  Clearing existing questions...');
        
        DB::beginTransaction();
        
        try {
            // Clear existing questions
            GeoQuestion::truncate();
            
            $this->info('📥 Inserting randomized questions...');
            
            // Insert in chunks for better performance
            $chunks = array_chunk($questions, 100);
            $totalChunks = count($chunks);
            
            // Insert chunks with simple progress messages
            foreach ($chunks as $index => $chunk) {
                // Remove the 'source' field before insertion
                $cleanChunk = array_map(function ($question) {
                    unset($question['source']);
                    return $question;
                }, $chunk);
                
                GeoQuestion::insert($cleanChunk);
                
                // Show progress
                $current = $index + 1;
                $this->info("Inserted chunk {$current}/{$totalChunks}");
            }
            
            DB::commit();
            
            $insertedCount = count($questions);
            $this->info("✅ Successfully inserted {$insertedCount} randomized questions!");
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->error("❌ Error during insertion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Display final statistics
     */
    private function displayFinalStatistics(): void
    {
        $this->info('📊 === FINAL STATISTICS ===');
        
        $totalCount = GeoQuestion::count();
        
        // Get all categories dynamically
        $categories = GeoQuestion::select('category')
            ->groupBy('category')
            ->orderBy('category')
            ->pluck('category')
            ->toArray();
        
        // Get all difficulties
        $difficulties = GeoQuestion::select('difficulty')
            ->groupBy('difficulty')
            ->orderBy('difficulty')
            ->pluck('difficulty')
            ->toArray();
        
        $tableData = [
            ['Total Questions', $totalCount, '100%'],
            ['', '', ''],
        ];
        
        // Add category stats
        foreach ($categories as $category) {
            $count = GeoQuestion::where('category', $category)->count();
            $percentage = round(($count / $totalCount) * 100, 1) . '%';
            $tableData[] = [ucfirst($category), $count, $percentage];
        }
        
        $tableData[] = ['', '', ''];
        
        // Add difficulty stats
        foreach ($difficulties as $difficulty) {
            $count = GeoQuestion::where('difficulty', $difficulty)->count();
            $percentage = round(($count / $totalCount) * 100, 1) . '%';
            $tableData[] = [ucfirst($difficulty), $count, $percentage];
        }
        
        $this->table(['Metric', 'Count', 'Percentage'], $tableData);
        
        $this->info('🎉 Database now contains ' . $totalCount . ' randomized questions!');
        $this->info('🔀 All questions inserted in completely random order (no validation applied)');
        $this->info('🎮 Ready for endless gameplay with maximum variety!');
    }
} 