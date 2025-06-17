<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Remove old instructions from config
            DB::statement('UPDATE games SET config = JSON_REMOVE(config, "$.instructions") WHERE JSON_CONTAINS_PATH(config, "one", "$.instructions")');
            
            // Add new instructions JSON column
            $table->json('instructions')->nullable()->after('config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }
}; 