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
        Schema::create('user_marketplace_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('marketplace_item_id')->constrained('marketplace_items')->onDelete('cascade');
            $table->unsignedInteger('token_cost');
            $table->decimal('etb_value', 10, 2);
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('completed');
            $table->string('reference')->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('purchased_at');
            $table->timestamps();

            $table->index(['user_id', 'purchased_at']);
            $table->index(['marketplace_item_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_marketplace_purchases');
    }
};
