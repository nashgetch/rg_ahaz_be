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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('phone', 20);
            $table->string('status', 20)->default('active');
            $table->string('provider_reference')->nullable();
            $table->string('external_reference')->nullable();
            $table->decimal('amount_etb', 10, 2)->default(0);
            $table->unsignedInteger('tokens_awarded')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'ends_at']);
            $table->index(['phone', 'paid_at']);
            $table->unique('provider_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
