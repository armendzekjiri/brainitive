<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox archive (BRAI-102): archived notifications leave the bell and the inbox but stay
 * reachable under "Archived" until App\Models\InboxNotification prunes them (90 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->index(['notifiable_type', 'notifiable_id', 'archived_at', 'created_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_inbox_index');
            $table->dropColumn('archived_at');
        });
    }
};
