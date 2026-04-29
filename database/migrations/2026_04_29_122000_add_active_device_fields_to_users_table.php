<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('active_device_name', 120)->nullable()->after('daily_bonus_claimed_at');
            $table->unsignedBigInteger('active_device_token_id')->nullable()->after('active_device_name');
            $table->timestamp('active_device_last_seen_at')->nullable()->after('active_device_token_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'active_device_name',
                'active_device_token_id',
                'active_device_last_seen_at',
            ]);
        });
    }
};
