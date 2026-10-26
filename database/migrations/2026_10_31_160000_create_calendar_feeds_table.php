<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private calendar feeds (BRAI-142): one ICS subscription URL per user for Apple Calendar, Outlook or
 * Google Calendar. The token is looked up by its sha256 hash; an encrypted copy lets the owner copy
 * the URL again. Reset = revoke the row and issue a new one, so the old URL stops working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 of the URL token
            $table->text('token'); // encrypted (model cast), only for showing the URL to its owner again
            $table->json('options')->nullable(); // what goes in (CalendarFeed::DEFAULT_OPTIONS)
            $table->char('content_hash', 64)->nullable(); // ETag of the last body served
            $table->timestamp('content_changed_at')->nullable(); // Last-Modified: when that body last changed
            $table->timestamp('last_fetched_at')->nullable();
            $table->unsignedInteger('fetch_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // one active feed per user (the app also revokes the old one in the same transaction)
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX calendar_feeds_one_active_per_user ON calendar_feeds (user_id) WHERE revoked_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
