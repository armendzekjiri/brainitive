<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Push devices (BRAI-154): which browser a subscription belongs to and what the push service
     * last answered, so Profile → Notifications can explain a device that stays silent.
     * expired_at = the push service answered 404 / 410; the row is kept (flagged) instead of deleted.
     */
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('user_agent', 255)->nullable();
            $table->string('device_label', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('expired_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['expired_at']);
            $table->dropColumn(['user_agent', 'device_label', 'last_used_at', 'last_success_at', 'last_error', 'last_error_at', 'expired_at']);
        });
    }
};
