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
        // Add 'replay_waiting' to the status ENUM column
        DB::statement("ALTER TABLE multiplayer_rooms MODIFY COLUMN status ENUM('waiting', 'starting', 'in_progress', 'completed', 'cancelled', 'replay_waiting') DEFAULT 'waiting'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'replay_waiting' from the status ENUM column
        DB::statement("ALTER TABLE multiplayer_rooms MODIFY COLUMN status ENUM('waiting', 'starting', 'in_progress', 'completed', 'cancelled') DEFAULT 'waiting'");
    }
};
