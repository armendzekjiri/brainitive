<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user preferences (v1.39.0). Both are JSON blobs owned by one feature each:
 * - notification_preferences: per category, which channels deliver (in_app / push / email).
 *   Shape and defaults: App\Notifications\NotificationCatalog.
 * - sidebar_preferences: hidden top-level entries and per-project sidebar groups / pins.
 * Null means "defaults" — never backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
            $table->json('sidebar_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notification_preferences', 'sidebar_preferences']);
        });
    }
};
