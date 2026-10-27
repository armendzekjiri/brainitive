<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking pages (v1.45.0, BRAI-158): public "book a time with me" pages. A user has several pages
 * (each with its own busy sources), a page has meeting types, a booking is one confirmed time.
 * Times are stored in UTC; a page's weekly hours are wall-clock times in the page's timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('public_slug', 64)->unique();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->string('timezone', 64);
            $table->boolean('enabled')->default(true);
            // {connections: [ids], issues: bool, tasks: bool, all_day_blocks: bool}
            $table->json('busy_sources')->nullable();
            // "also create a card": nullable board + column (checked against edit rights)
            $table->foreignId('card_board_id')->nullable()->constrained('boards')->nullOnDelete();
            $table->foreignId('card_column_id')->nullable()->constrained('board_columns')->nullOnDelete();
            $table->json('settings')->nullable(); // consent, consent_text
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
        });

        Schema::create('booking_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_page_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->json('location')->nullable(); // {kind: google_meet|teams|phone|in_person|link|none, value}
            $table->json('availability'); // {weekly: {mon: [["09:00","17:00"]], …}, overrides: {"Y-m-d": [[…]]}}
            $table->json('rules')->nullable(); // buffers, notice, horizon, daily max, step
            $table->foreignId('target_connection_id')->nullable()->constrained('calendar_connections')->nullOnDelete();
            $table->json('questions')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['booking_page_id', 'slug']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the host
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_timezone', 64)->nullable();
            $table->json('answers')->nullable();
            $table->text('consent_text')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('status', 16)->default('confirmed'); // confirmed | cancelled
            $table->string('manage_token_hash', 64)->unique(); // sha256 of the guest's cancel / reschedule token
            $table->foreignId('calendar_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_event_id')->nullable();
            $table->string('conference_url', 2048)->nullable();
            $table->json('unchecked_connection_ids')->nullable(); // calendars that couldn't be read at booking time
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancelled_by', 8)->nullable(); // host | guest
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'starts_at']);
        });

        // the last line against double booking: one confirmed booking per host and start time
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX bookings_host_start_confirmed ON bookings (user_id, starts_at) WHERE status = 'confirmed'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_types');
        Schema::dropIfExists('booking_pages');
    }
};
