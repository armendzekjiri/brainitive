<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks item start-time reminders already pushed to a user, so the scheduler
 * never sends the same reminder twice. Separate from `sent_reminders` (which is
 * calendar-connection scoped) because item reminders are user scoped and have
 * no connection. Key encodes the start epoch, so rescheduling an item fires a
 * fresh reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_item_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stable per-occurrence key, e.g. "board_item:<id>:<startEpoch>".
            $table->string('reminder_key');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'reminder_key'], 'sent_item_reminders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_item_reminders');
    }
};
