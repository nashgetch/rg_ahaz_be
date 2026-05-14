<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_marketplace_purchases', function (Blueprint $table) {
            $table->string('redemption_phone', 20)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('user_marketplace_purchases', function (Blueprint $table) {
            $table->dropColumn('redemption_phone');
        });
    }
};
