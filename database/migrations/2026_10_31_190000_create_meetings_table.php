<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online meetings scheduled from an issue or a document (BRAI-147). The event lives in the
 * organiser's calendar (Google / Outlook); this row links it to its subject so the card / doc can
 * show it. No soft deletes: removing a meeting either cancels the calendar event or only unlinks
 * it, and nothing else points at the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->morphs('subject'); // App\Models\Item | App\Models\Page
            $table->foreignId('calendar_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40); // calendar provider key ('google', 'outlook', …)
            $table->string('external_event_id', 1024);
            $table->string('conference_provider', 20)->nullable(); // google_meet | teams | other; null = no call
            $table->text('join_url')->nullable();
            $table->text('event_url')->nullable(); // "open in calendar"
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->json('attendees')->nullable(); // [{email, name, user_id}] — no secrets
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('meeting_notes_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'starts_at']);
            // FK columns Postgres doesn't index itself (SET NULL on delete of a connection / user / page)
            $table->index('calendar_connection_id');
            $table->index('created_by');
            $table->index('meeting_notes_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
