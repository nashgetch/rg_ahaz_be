<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GeoQuestion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class SeedGeoQuestions extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'geo:seed-questions 
                            {file? : Path to the JSON file containing questions}
                            {--force : Force seeding even if questions already exist}
                            {--dry-run : Preview what would be done without making changes}
                            {--backup : Create a backup before seeding}
                            {--comprehensive : Merge existing questions with new 5000 questions for 7000 total}
                            {--new-file=trivia_5000_questions.json : Path to new questions file for comprehensive seeding}';

    /**
     * The console command description.
     */
    protected $description = 'Seed geography questions from JSON file into the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🌍 GeoSprint Question Seeder');
        $this->info('================================');

        // Check if comprehensive seeding is requested
        if ($this->option('comprehensive')) {
            return $this->handleComprehensiveSeeding();
        }

        // Regular seeding logic
        return $this->handleRegularSeeding();
    }

    /**
     * Handle comprehensive seeding (merge 2000 + 5000 = 7000)
     */
    private function handleComprehensiveSeeding(): int
    {
        $this->info('🚀 COMPREHENSIVE SEEDING MODE');
        $this->info('Target: Merge existing + 5000 new questions');
        $this->newLine();

        try {
            $this->call('db:seed', [
                '--class' => 'ComprehensiveGeoQuestionSeeder',
                '--force' => true
            ]);
            
            $this->newLine();
            $this->info('✅ Comprehensive seeding completed successfully!');
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Comprehensive seeding failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Handle regular seeding
     */
    private function handleRegularSeeding(): int
    {
        // Get file path
        $filePath = $this->argument('file');
        if (!$filePath) {
            $filePath = $this->findJsonFile();
        }

        if (!$filePath) {
            $this->error('❌ No JSON file found or specified.');
            $this->info('💡 Usage: php artisan geo:seed-questions [file-path]');
            $this->info('💡 Or use: php artisan geo:seed-questions --comprehensive');
            return 1;
        }

        if (!File::exists($filePath)) {
            $this->error("❌ File not found: {$filePath}");
            return 1;
        }

        $this->info("📄 Using file: {$filePath}");

        // Safety checks
        $existingCount = GeoQuestion::count();
        if ($existingCount > 0 && !$this->option('force')) {
            $this->warn("⚠️  Database already contains {$existingCount} questions!");
            
            if (!$this->confirm('Do you want to continue? This will replace all existing questions.')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        // Read and validate JSON
        $questionsData = $this->readAndValidateJson($filePath);
        if (!$questionsData) {
            return 1;
        }

        $totalQuestions = count($questionsData);
        $this->info("📊 Found {$totalQuestions} questions in file");

        // Validate questions
        $validQuestions = $this->validateQuestions($questionsData);
        if (empty($validQuestions)) {
            $this->error('❌ No valid questions found in file!');
            return 1;
        }

        $validCount = count($validQuestions);
        $this->info("✅ {$validCount} valid questions ready for import");

        // Dry run check
        if ($this->option('dry-run')) {
            $this->info('🔍 DRY RUN - No changes will be made');
            $this->displayPreview($validQuestions);
            return 0;
        }

        // Backup if requested
        if ($this->option('backup') && $existingCount > 0) {
            $this->createBackup();
        }

        // Confirm before proceeding
        if (!$this->option('force')) {
            $message = $existingCount > 0 
                ? "Replace {$existingCount} existing questions with {$validCount} new questions?"
                : "Import {$validCount} questions into the database?";
                
            if (!$this->confirm($message)) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        // Perform the seeding
        return $this->performSeeding($validQuestions, $existingCount);
    }

    /**
     * Find JSON file in common locations
     */
    private function findJsonFile(): ?string
    {
        $possiblePaths = [
            'geography_trivia_questions_varied.json',
            '../geography_trivia_questions_varied.json',
            storage_path('app/geography_trivia_questions_varied.json'),
            base_path('geography_trivia_questions_varied.json'),
        ];

        foreach ($possiblePaths as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Read and validate JSON file
     */
    private function readAndValidateJson(string $filePath): ?array
    {
        $this->info('📖 Reading JSON file...');
        
        try {
            $jsonContent = File::get($filePath);
            $data = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('❌ Invalid JSON: ' . json_last_error_msg());
                return null;
            }

            if (!is_array($data)) {
                $this->error('❌ JSON file must contain an array of questions');
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            $this->error('❌ Error reading file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate and prepare questions
     */
    private function validateQuestions(array $questionsData): array
    {
        $this->info('🔍 Validating questions...');
        
        $validQuestions = [];
        $invalidCount = 0;
        $progressBar = $this->output->createProgressBar(count($questionsData));

        foreach ($questionsData as $questionData) {
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
                ];
            } else {
                $invalidCount++;
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($invalidCount > 0) {
            $this->warn("⚠️  Skipped {$invalidCount} invalid questions");
        }

        return $validQuestions;
    }

    /**
     * Validate individual question
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
     * Display preview of questions to be imported
     */
    private function displayPreview(array $validQuestions): void
    {
        $categories = array_count_values(array_column($validQuestions, 'category'));
        $difficulties = array_count_values(array_column($validQuestions, 'difficulty'));

        $this->table(['Category', 'Count'], array_map(fn($k, $v) => [$k, $v], array_keys($categories), $categories));
        $this->table(['Difficulty', 'Count'], array_map(fn($k, $v) => [$k, $v], array_keys($difficulties), $difficulties));

        // Show sample questions
        $this->info('📝 Sample questions:');
        for ($i = 0; $i < min(3, count($validQuestions)); $i++) {
            $q = $validQuestions[$i];
            $this->line("  • [{$q['category']}] {$q['question']}");
        }
    }

    /**
     * Create backup of existing questions
     */
    private function createBackup(): void
    {
        $this->info('💾 Creating backup...');
        $filename = 'geo_questions_backup_' . date('Y-m-d_H-i-s') . '.json';
        $backupPath = storage_path("app/backups/{$filename}");
        
        // Ensure backup directory exists
        $backupDir = dirname($backupPath);
        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $existingQuestions = GeoQuestion::all()->map(function ($question) {
            return [
                'id' => $question->question_id,
                'question' => $question->question,
                'options' => $question->options,
                'correctAnswer' => $question->correct_answer,
                'category' => $question->category,
                'difficulty' => $question->difficulty,
                'explanation' => $question->explanation,
                'points' => $question->points,
            ];
        });

        File::put($backupPath, json_encode($existingQuestions, JSON_PRETTY_PRINT));
        $this->info("✅ Backup created: {$backupPath}");
    }

    /**
     * Perform the actual seeding
     */
    private function performSeeding(array $validQuestions, int $existingCount): int
    {
        DB::beginTransaction();
        
        try {
            // Clear existing questions
            if ($existingCount > 0) {
                $this->info('🗑️  Clearing existing questions...');
                GeoQuestion::truncate();
            }

            // Insert new questions
            $this->info('📥 Inserting questions...');
            $chunks = array_chunk($validQuestions, 100);
            $progressBar = $this->output->createProgressBar(count($chunks));

            foreach ($chunks as $chunk) {
                GeoQuestion::insert($chunk);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            DB::commit();

            $this->info('✅ Successfully seeded ' . count($validQuestions) . ' questions!');
            $this->displayFinalStatistics();

            return 0;

        } catch (\Exception $e) {
            DB::rollback();
            $this->error('❌ Error during seeding: ' . $e->getMessage());
            $this->error('🔄 All changes have been rolled back.');
            return 1;
        }
    }

    /**
     * Display final statistics
     */
    private function displayFinalStatistics(): void
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

        $this->info('📊 Final Statistics:');
        $this->table(['Metric', 'Count', 'Percentage'], [
            ['Total Questions', $stats['total'], '100%'],
            ['Ethiopia', $stats['ethiopia'], round(($stats['ethiopia']/$stats['total'])*100, 1) . '%'],
            ['Africa', $stats['africa'], round(($stats['africa']/$stats['total'])*100, 1) . '%'],
            ['World', $stats['world'], round(($stats['world']/$stats['total'])*100, 1) . '%'],
            ['Easy', $stats['easy'], round(($stats['easy']/$stats['total'])*100, 1) . '%'],
            ['Medium', $stats['medium'], round(($stats['medium']/$stats['total'])*100, 1) . '%'],
            ['Hard', $stats['hard'], round(($stats['hard']/$stats['total'])*100, 1) . '%'],
        ]);
    }
} 