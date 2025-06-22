<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GeoQuestion;
use Illuminate\Support\Facades\Http;

class TestGeoRandomization extends Command
{
    protected $signature = 'geo:test-randomization 
                            {--api-only : Test only API endpoints}
                            {--db-only : Test only database randomization}';

    protected $description = 'Test GeoSprint question randomization and uniqueness features';

    public function handle(): int
    {
        $this->info('🧪 Testing GeoSprint Randomization Features');
        $this->info('==========================================');

        if (!$this->option('api-only')) {
            $this->testDatabaseRandomization();
        }

        if (!$this->option('db-only')) {
            $this->testAPIEndpoints();
        }

        $this->info('🎉 All tests completed!');
        return 0;
    }

    private function testDatabaseRandomization(): void
    {
        $this->info('📊 Testing Database Question Randomization...');
        $this->newLine();

        // Test 1: Check total count
        $totalCount = GeoQuestion::count();
        $this->info("Total questions in database: {$totalCount}");

        if ($totalCount === 0) {
            $this->error('❌ No questions found in database! Run seeder first.');
            return;
        }

        // Test 2: Check if questions are stored in random order
        $first10 = GeoQuestion::orderBy('id')->limit(10)->get(['id', 'question_id', 'category', 'difficulty']);
        
        $this->info('🔍 First 10 questions (by database ID):');
        $this->table(['DB ID', 'Question ID', 'Category', 'Difficulty'], 
            $first10->map(fn($q) => [$q->id, $q->question_id, $q->category, $q->difficulty])->toArray()
        );

        // Test 3: Check category distribution
        $categoryStats = [
            'ethiopia' => GeoQuestion::where('category', 'ethiopia')->count(),
            'africa' => GeoQuestion::where('category', 'africa')->count(),
            'world' => GeoQuestion::where('category', 'world')->count(),
        ];

        $this->info('📊 Category Distribution:');
        foreach ($categoryStats as $category => $count) {
            $percentage = round(($count / $totalCount) * 100, 1);
            $this->info("  {$category}: {$count} ({$percentage}%)");
        }

        // Test 4: Check difficulty distribution
        $difficultyStats = [
            'easy' => GeoQuestion::where('difficulty', 'easy')->count(),
            'medium' => GeoQuestion::where('difficulty', 'medium')->count(),
            'hard' => GeoQuestion::where('difficulty', 'hard')->count(),
        ];

        $this->info('📊 Difficulty Distribution:');
        foreach ($difficultyStats as $difficulty => $count) {
            $percentage = round(($count / $totalCount) * 100, 1);
            $this->info("  {$difficulty}: {$count} ({$percentage}%)");
        }

        // Test 5: Check for randomization (categories should be mixed)
        $categoryPattern = $first10->pluck('category')->toArray();
        $isRandomized = count(array_unique($categoryPattern)) > 1;
        
        if ($isRandomized) {
            $this->info('✅ Questions appear to be randomized in database');
        } else {
            $this->warn('⚠️  Questions might not be properly randomized');
        }

        $this->newLine();
    }

    private function testAPIEndpoints(): void
    {
        $this->info('🌐 Testing API Endpoint Randomization...');
        $this->newLine();

        $baseUrl = config('app.url', 'http://localhost:8000');
        $apiUrl = "{$baseUrl}/api/v1/geo-questions";

        // Test 1: Basic API call
        $this->info('Test 1: Basic API call (10 questions)');
        try {
            $response1 = Http::get($apiUrl, ['count' => 10, 'variety' => true]);
            
            if ($response1->successful()) {
                $data1 = $response1->json();
                $this->info("✅ Fetched {$data1['count']} questions");
                
                // Show variety
                $categories = collect($data1['questions'])->pluck('category')->unique()->values()->toArray();
                $difficulties = collect($data1['questions'])->pluck('difficulty')->unique()->values()->toArray();
                $this->info("  Categories: " . implode(', ', $categories));
                $this->info("  Difficulties: " . implode(', ', $difficulties));
            } else {
                $this->error('❌ API call failed: ' . $response1->body());
                return;
            }
        } catch (\Exception $e) {
            $this->error('❌ API call failed: ' . $e->getMessage());
            return;
        }

        // Test 2: API call with exclusions
        $this->info('Test 2: API call with exclusions');
        try {
            $excludeIds = collect($data1['questions'])->pluck('id')->take(5)->implode(',');
            $this->info("  Excluding IDs: {$excludeIds}");
            
            $response2 = Http::get($apiUrl, [
                'count' => 10,
                'variety' => true,
                'exclude_ids' => $excludeIds
            ]);
            
            if ($response2->successful()) {
                $data2 = $response2->json();
                $this->info("✅ Fetched {$data2['count']} questions with {$data2['excluded_count']} exclusions");
                
                // Check no overlap
                $ids1 = collect($data1['questions'])->pluck('id')->toArray();
                $ids2 = collect($data2['questions'])->pluck('id')->toArray();
                $overlap = array_intersect($ids1, $ids2);
                
                if (empty($overlap)) {
                    $this->info('✅ No question overlap between calls');
                } else {
                    $this->warn('⚠️  Found overlapping questions: ' . implode(', ', $overlap));
                }
            } else {
                $this->error('❌ API call with exclusions failed: ' . $response2->body());
            }
        } catch (\Exception $e) {
            $this->error('❌ API call with exclusions failed: ' . $e->getMessage());
        }

        // Test 3: Multiple calls to check variety
        $this->info('Test 3: Multiple API calls to check variety');
        $allQuestionIds = [];
        
        for ($i = 1; $i <= 3; $i++) {
            try {
                $response = Http::get($apiUrl, ['count' => 5, 'variety' => true]);
                
                if ($response->successful()) {
                    $data = $response->json();
                    $questionIds = collect($data['questions'])->pluck('id')->toArray();
                    $allQuestionIds = array_merge($allQuestionIds, $questionIds);
                    
                    $this->info("  Call {$i}: " . implode(', ', $questionIds));
                } else {
                    $this->error("❌ API call {$i} failed");
                }
            } catch (\Exception $e) {
                $this->error("❌ API call {$i} failed: " . $e->getMessage());
            }
        }

        // Check uniqueness across calls
        $uniqueIds = array_unique($allQuestionIds);
        $uniqueCount = count($uniqueIds);
        $totalFetched = count($allQuestionIds);
        
        $this->info("Total questions fetched: {$totalFetched}");
        $this->info("Unique questions: {$uniqueCount}");
        
        if ($uniqueCount === $totalFetched) {
            $this->info('✅ All questions across multiple calls were unique');
        } else {
            $duplicates = $totalFetched - $uniqueCount;
            $this->warn("⚠️  Found {$duplicates} duplicate questions across calls");
        }

        $this->newLine();
    }
} 