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
            $table->string('phone')->unique()->after('email');
            $table->string('locale', 5)->default('en')->after('phone');
            $table->unsignedInteger('tokens_balance')->default(0)->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->timestamp('daily_bonus_claimed_at')->nullable()->after('last_login_at');
            $table->json('preferences')->nullable()->after('daily_bonus_claimed_at');
            
            // Remove email requirement and make it nullable for phone-based auth
            $table->string('email')->nullable()->change();
            $table->timestamp('email_verified_at')->nullable()->change();
            
            $table->index('phone');
            $table->index('tokens_balance');
            $table->index('last_login_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'locale',
                'tokens_balance',
                'last_login_at',
                'daily_bonus_claimed_at',
                'preferences'
            ]);
            
            $table->string('email')->nullable(false)->change();
        });
    }
};
