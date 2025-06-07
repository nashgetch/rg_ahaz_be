<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Load Laravel app
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 AHAZ Database Index Verification\n";
echo "====================================\n\n";

// Function to check if index exists
function checkIndex($table, $indexName, $description) {
    try {
        $indexes = collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->unique()
            ->toArray();
        
        $exists = in_array($indexName, $indexes);
        $status = $exists ? "✅ EXISTS" : "❌ MISSING";
        
        echo sprintf("%-50s %s\n", "{$table}.{$indexName}", $status);
        
        return $exists;
    } catch (Exception $e) {
        echo sprintf("%-50s %s\n", "{$table}.{$indexName}", "⚠️  ERROR: " . $e->getMessage());
        return false;
    }
}

// Function to get table row count
function getTableStats($table) {
    try {
        $count = DB::table($table)->count();
        $size = DB::select("SELECT 
            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
            FROM information_schema.TABLES 
            WHERE table_schema = DATABASE() AND table_name = '{$table}'")[0]->size_mb ?? 0;
        
        return "({$count} rows, {$size} MB)";
    } catch (Exception $e) {
        return "(stats unavailable)";
    }
}

$totalIndexes = 0;
$existingIndexes = 0;

echo "📊 Table Statistics:\n";
echo "-------------------\n";
$tables = ['users', 'rounds', 'leaderboards', 'transactions', 'otps'];
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        echo sprintf("%-20s %s\n", $table, getTableStats($table));
    }
}
echo "\n";

echo "🏗️  Core Game Tables:\n";
echo "-------------------\n";

// Users table indexes
if (Schema::hasTable('users')) {
    echo "Users Table:\n";
    $existingIndexes += checkIndex('users', 'users_phone_last_login_idx', 'Phone & Login Activity') ? 1 : 0;
    $existingIndexes += checkIndex('users', 'users_level_experience_idx', 'Level & Experience') ? 1 : 0;
    $existingIndexes += checkIndex('users', 'users_level_tokens_idx', 'Level & Tokens') ? 1 : 0;
    $existingIndexes += checkIndex('users', 'users_daily_bonus_idx', 'Daily Bonus') ? 1 : 0;
    $existingIndexes += checkIndex('users', 'users_created_locale_idx', 'Created & Locale') ? 1 : 0;
    $totalIndexes += 5;
    echo "\n";
}

// Rounds table indexes
if (Schema::hasTable('rounds')) {
    echo "Rounds Table:\n";
    $existingIndexes += checkIndex('rounds', 'rounds_user_game_completed_idx', 'User Game Completed') ? 1 : 0;
    $existingIndexes += checkIndex('rounds', 'rounds_game_user_score_idx', 'Game User Score') ? 1 : 0;
    $existingIndexes += checkIndex('rounds', 'rounds_game_completed_status_idx', 'Game Completed Status') ? 1 : 0;
    $existingIndexes += checkIndex('rounds', 'rounds_user_started_status_idx', 'User Started Status') ? 1 : 0;
    $existingIndexes += checkIndex('rounds', 'rounds_game_duration_idx', 'Game Duration') ? 1 : 0;
    $existingIndexes += checkIndex('rounds', 'rounds_hash_status_idx', 'Hash Status') ? 1 : 0;
    $totalIndexes += 6;
    echo "\n";
}

// Leaderboards table indexes
if (Schema::hasTable('leaderboards')) {
    echo "Leaderboards Table:\n";
    $existingIndexes += checkIndex('leaderboards', 'leaderboards_user_score_idx', 'User Score') ? 1 : 0;
    $existingIndexes += checkIndex('leaderboards', 'leaderboards_user_activity_idx', 'User Activity') ? 1 : 0;
    $existingIndexes += checkIndex('leaderboards', 'leaderboards_game_plays_idx', 'Game Plays') ? 1 : 0;
    $existingIndexes += checkIndex('leaderboards', 'leaderboards_game_rank_score_idx', 'Game Rank Score') ? 1 : 0;
    $existingIndexes += checkIndex('leaderboards', 'leaderboards_daily_activity_idx', 'Daily Activity') ? 1 : 0;
    $existingIndexes += checkIndex('leaderboards', 'leaderboards_weekly_activity_idx', 'Weekly Activity') ? 1 : 0;
    $totalIndexes += 6;
    echo "\n";
}

// Transactions table indexes
if (Schema::hasTable('transactions')) {
    echo "Transactions Table:\n";
    $existingIndexes += checkIndex('transactions', 'transactions_user_type_date_idx', 'User Type Date') ? 1 : 0;
    $existingIndexes += checkIndex('transactions', 'transactions_type_status_date_idx', 'Type Status Date') ? 1 : 0;
    $existingIndexes += checkIndex('transactions', 'transactions_amount_type_idx', 'Amount Type') ? 1 : 0;
    $existingIndexes += checkIndex('transactions', 'transactions_status_date_idx', 'Status Date') ? 1 : 0;
    $existingIndexes += checkIndex('transactions', 'transactions_reference_status_idx', 'Reference Status') ? 1 : 0;
    $totalIndexes += 5;
    echo "\n";
}

// OTPs table indexes
if (Schema::hasTable('otps')) {
    echo "OTPs Table:\n";
    $existingIndexes += checkIndex('otps', 'otps_validation_idx', 'Validation') ? 1 : 0;
    $existingIndexes += checkIndex('otps', 'otps_cleanup_idx', 'Cleanup') ? 1 : 0;
    $existingIndexes += checkIndex('otps', 'otps_ip_rate_limit_idx', 'IP Rate Limit') ? 1 : 0;
    $existingIndexes += checkIndex('otps', 'otps_attempts_idx', 'Attempts') ? 1 : 0;
    $totalIndexes += 4;
    echo "\n";
}

echo "🔧 System Tables:\n";
echo "----------------\n";

// System table indexes
if (Schema::hasTable('personal_access_tokens')) {
    echo "Personal Access Tokens Table:\n";
    $existingIndexes += checkIndex('personal_access_tokens', 'personal_access_tokens_token_validation_idx', 'Token Validation') ? 1 : 0;
    $existingIndexes += checkIndex('personal_access_tokens', 'personal_access_tokens_expires_idx', 'Expires') ? 1 : 0;
    $totalIndexes += 2;
    echo "\n";
}

if (Schema::hasTable('sessions')) {
    echo "Sessions Table:\n";
    $existingIndexes += checkIndex('sessions', 'sessions_user_activity_idx', 'User Activity') ? 1 : 0;
    $existingIndexes += checkIndex('sessions', 'sessions_last_activity_idx', 'Last Activity') ? 1 : 0;
    $totalIndexes += 2;
    echo "\n";
}

echo "📈 Summary:\n";
echo "----------\n";
echo sprintf("Total Indexes Expected: %d\n", $totalIndexes);
echo sprintf("Indexes Found: %d\n", $existingIndexes);
echo sprintf("Success Rate: %.1f%%\n", ($existingIndexes / $totalIndexes) * 100);

if ($existingIndexes === $totalIndexes) {
    echo "\n🎉 All performance indexes are properly installed!\n";
    echo "Your database is optimized for AHAZ gaming platform.\n\n";
    
    echo "🚀 Performance Benefits:\n";
    echo "- Faster user authentication and login\n";
    echo "- Optimized leaderboard calculations\n";
    echo "- Efficient game history retrieval\n";
    echo "- Improved transaction processing\n";
    echo "- Enhanced OTP validation\n\n";
    
    echo "📚 Documentation: backend/docs/DATABASE_INDEXES.md\n";
} else {
    echo sprintf("\n⚠️  Missing %d indexes. Please check the migration status.\n", $totalIndexes - $existingIndexes);
    echo "Run: php artisan migrate:status\n";
}

echo "\n✨ Index verification complete!\n"; 