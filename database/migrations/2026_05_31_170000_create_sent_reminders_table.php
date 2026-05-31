<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks calendar/task reminders already pushed, so the scheduler never sends
 * the same reminder twice no matter how often it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            // Stable per-occurrence key, e.g. "event:<googleId>:<startEpoch>".
            $table->string('reminder_key');
            $table->timestamp('created_at')->nullable();

            $table->unique(['calendar_connection_id', 'reminder_key'], 'sent_reminders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_reminders');
    }
};
