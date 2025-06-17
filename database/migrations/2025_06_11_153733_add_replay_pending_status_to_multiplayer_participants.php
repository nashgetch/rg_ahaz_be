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
        // Add 'replay_pending' to the status ENUM column
        DB::statement("ALTER TABLE multiplayer_participants MODIFY COLUMN status ENUM('invited', 'joined', 'ready', 'playing', 'finished', 'disconnected', 'replay_pending') DEFAULT 'joined'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'replay_pending' from the status ENUM column
        DB::statement("ALTER TABLE multiplayer_participants MODIFY COLUMN status ENUM('invited', 'joined', 'ready', 'playing', 'finished', 'disconnected') DEFAULT 'joined'");
    }
};
