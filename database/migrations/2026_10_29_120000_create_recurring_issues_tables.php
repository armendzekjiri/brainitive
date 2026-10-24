<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring issues (v1.40.0, BRAI-123): an issue template on a board with a schedule, and one row per
 * occurrence the scheduler created (unique per template + date = idempotency; links the issue back).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // also the actor of every run

            // the issue
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('item_type_id')->nullable()->constrained('item_types')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('item_priorities')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('label_ids')->nullable();
            $table->foreignId('column_id')->nullable()->constrained('board_columns')->nullOnDelete(); // null = first column
            $table->unsignedSmallInteger('due_in_days')->nullable(); // due = occurrence + N days; null = no due date

            // the schedule (App\Support\RecurringIssues\RecurrenceSchedule)
            $table->string('frequency', 16); // daily | weekly | monthly | yearly
            $table->unsignedSmallInteger('interval')->default(1);
            $table->json('weekdays')->nullable(); // ISO 1 (Mon) … 7 (Sun), weekly
            $table->string('monthly_mode', 16)->nullable(); // day | weekday
            $table->unsignedTinyInteger('month_day')->nullable(); // 1–31 (31 = last day in shorter months)
            $table->smallInteger('month_week')->nullable(); // 1–4, -1 = last
            $table->unsignedTinyInteger('month_weekday')->nullable(); // 1–7, 0 = any weekday (Mon–Fri)
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->string('timezone', 64);

            // state
            $table->date('active_from'); // nothing before this local date is created (save / resume day)
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->date('last_occurrence_date')->nullable();
            $table->date('next_occurrence_date')->nullable(); // null = finished
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['paused_at', 'next_occurrence_date']);
        });

        Schema::create('recurring_issue_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_issue_id')->constrained()->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['recurring_issue_id', 'occurrence_date']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_issue_occurrences');
        Schema::dropIfExists('recurring_issues');
    }
};
