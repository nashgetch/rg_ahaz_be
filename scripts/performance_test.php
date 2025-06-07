<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Load Laravel app
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "⚡ AHAZ Database Performance Test\n";
echo "=================================\n\n";

// Function to benchmark query execution time
function benchmarkQuery($description, $query, $bindings = []) {
    echo sprintf("Testing: %s\n", $description);
    
    $startTime = microtime(true);
    
    try {
        if (is_string($query)) {
            $result = DB::select($query, $bindings);
        } else {
            $result = $query();
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        $resultCount = is_array($result) ? count($result) : (is_countable($result) ? $result->count() : 'N/A');
        
        echo sprintf("  ⏱️  Execution Time: %.2f ms\n", $executionTime);
        echo sprintf("  📊 Results: %s rows\n", $resultCount);
        
        // Get query execution plan
        if (is_string($query)) {
            $explain = DB::select("EXPLAIN " . $query, $bindings);
            $usingIndex = false;
            foreach ($explain as $row) {
                if (isset($row->key) && $row->key && $row->key !== 'NULL') {
                    $usingIndex = true;
                    echo sprintf("  🔍 Using Index: %s\n", $row->key);
                    break;
                }
            }
            if (!$usingIndex) {
                echo "  ⚠️  No index used (table scan)\n";
            }
        }
        
        echo sprintf("  %s\n\n", $executionTime < 10 ? "🚀 EXCELLENT" : ($executionTime < 50 ? "✅ GOOD" : "⚠️  NEEDS OPTIMIZATION"));
        
        return $executionTime;
    } catch (Exception $e) {
        echo sprintf("  ❌ ERROR: %s\n\n", $e->getMessage());
        return -1;
    }
}

echo "🎮 Core Gaming Queries:\n";
echo "----------------------\n";

$totalTime = 0;
$testCount = 0;

// Test 1: User authentication query
$time = benchmarkQuery(
    "User Authentication (phone lookup)",
    "SELECT id, name, level, tokens_balance FROM users WHERE phone = ? AND last_login_at IS NOT NULL ORDER BY last_login_at DESC LIMIT 1",
    ['9XXXXXXXX']
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 2: Leaderboard query
$time = benchmarkQuery(
    "Game Leaderboard (top 10 players)",
    "SELECT u.name, l.best_score, l.rank_global FROM leaderboards l JOIN users u ON l.user_id = u.id WHERE l.game_id = ? ORDER BY l.best_score DESC LIMIT 10",
    [1]
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 3: User game history
$time = benchmarkQuery(
    "User Game History (recent rounds)",
    "SELECT r.score, r.completed_at, g.name as game_name FROM rounds r JOIN games g ON r.game_id = g.id WHERE r.user_id = ? AND r.status = 'completed' ORDER BY r.completed_at DESC LIMIT 20",
    [1]
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 4: Daily bonus check
$time = benchmarkQuery(
    "Daily Bonus Eligibility Check",
    "SELECT COUNT(*) as eligible_users FROM users WHERE daily_bonus_claimed_at IS NULL OR daily_bonus_claimed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 5: OTP validation
$time = benchmarkQuery(
    "OTP Validation Query",
    "SELECT * FROM otps WHERE phone = ? AND code = ? AND expires_at > NOW() AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1",
    ['9XXXXXXXX', '123456']
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 6: Transaction history
$time = benchmarkQuery(
    "User Transaction History",
    "SELECT amount, type, description, created_at FROM transactions WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
    [1]
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 7: User stats aggregation
$time = benchmarkQuery(
    "User Statistics Aggregation",
    function() {
        return DB::table('leaderboards')
            ->select('user_id')
            ->selectRaw('SUM(best_score) as total_score')
            ->selectRaw('COUNT(*) as games_played')
            ->selectRaw('MAX(last_played_at) as last_activity')
            ->where('user_id', 1)
            ->groupBy('user_id')
            ->get();
    }
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

// Test 8: Game popularity analysis
$time = benchmarkQuery(
    "Game Popularity Analysis",
    "SELECT game_id, COUNT(*) as players, AVG(best_score) as avg_score, SUM(total_plays) as total_plays FROM leaderboards GROUP BY game_id ORDER BY players DESC"
);
$totalTime += $time > 0 ? $time : 0;
$testCount++;

echo "📊 Performance Summary:\n";
echo "=====================\n";
echo sprintf("Total Tests: %d\n", $testCount);
echo sprintf("Average Query Time: %.2f ms\n", $totalTime / $testCount);
echo sprintf("Total Execution Time: %.2f ms\n", $totalTime);

if ($totalTime / $testCount < 20) {
    echo "\n🏆 EXCELLENT PERFORMANCE!\n";
    echo "Your database indexes are working perfectly.\n";
} elseif ($totalTime / $testCount < 50) {
    echo "\n✅ GOOD PERFORMANCE\n";
    echo "Database is well optimized for current load.\n";
} else {
    echo "\n⚠️  PERFORMANCE CONCERNS\n";
    echo "Consider reviewing query patterns and index usage.\n";
}

echo "\n🔧 Database Statistics:\n";
echo "=====================\n";

try {
    // Get database size
    $dbStats = DB::select("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_size_mb,
            ROUND(SUM(data_length) / 1024 / 1024, 2) AS data_size_mb,
            ROUND(SUM(index_length) / 1024 / 1024, 2) AS index_size_mb
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
    ")[0];
    
    echo sprintf("Database Size: %.2f MB\n", $dbStats->total_size_mb);
    echo sprintf("Data Size: %.2f MB\n", $dbStats->data_size_mb);
    echo sprintf("Index Size: %.2f MB\n", $dbStats->index_size_mb);
    echo sprintf("Index Overhead: %.1f%%\n", ($dbStats->index_size_mb / $dbStats->data_size_mb) * 100);
    
} catch (Exception $e) {
    echo "Database statistics unavailable\n";
}

echo "\n💡 Optimization Tips:\n";
echo "====================\n";
echo "1. Monitor slow query logs regularly\n";
echo "2. Use EXPLAIN to analyze query execution plans\n";
echo "3. Consider adding indexes for new query patterns\n";
echo "4. Regularly run ANALYZE TABLE to update statistics\n";
echo "5. Monitor index usage with SHOW INDEX queries\n";

echo "\n📚 More Info: backend/docs/DATABASE_INDEXES.md\n";
echo "✨ Performance test complete!\n"; 