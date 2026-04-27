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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('subscription_id')->nullable()->after('user_id');
            $table->string('provider_subscription_id')->nullable()->after('subscription_id');
            $table->string('event_type', 50)->nullable()->after('provider_subscription_id');
            $table->string('package_id')->nullable()->after('status');
            $table->string('method_id')->nullable()->after('package_id');
            $table->timestamp('trial_end')->nullable()->after('method_id');
            $table->timestamp('activation_date')->nullable()->after('trial_end');
            $table->timestamp('expires_at')->nullable()->after('activation_date');
            $table->date('active_on_date')->nullable()->after('expires_at');

            $table->index(['status', 'expires_at']);
            $table->index(['subscription_id']);
            $table->index(['provider_subscription_id']);
            $table->index(['active_on_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropIndex(['subscription_id']);
            $table->dropIndex(['provider_subscription_id']);
            $table->dropIndex(['active_on_date', 'status']);

            $table->dropColumn([
                'subscription_id',
                'provider_subscription_id',
                'event_type',
                'package_id',
                'method_id',
                'trial_end',
                'activation_date',
                'expires_at',
                'active_on_date',
            ]);
        });
    }
};
