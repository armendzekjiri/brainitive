<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public feedback portal (v1.42.0, BRAI-60): one opt-in portal per project, ideas ("posts") that
 * wait for moderation, one vote / follow per guest identity, a moderator audit trail and the
 * dedup table for follower mails. Public fields live here, never on board items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_portals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('slug', 60)->unique(); // [a-z0-9-], the public URL /feedback/{slug}
            $table->string('title', 120);
            $table->text('intro')->nullable(); // plain text
            $table->unsignedInteger('next_number')->default(0); // last post number used
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('feedback_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('feedback_portal_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // public, per portal: /feedback/{slug}/{number}
            $table->string('title', 160);
            $table->text('body')->nullable(); // plain text
            $table->foreignId('author_identity_id')->nullable()->constrained('portal_identities')->nullOnDelete();
            $table->string('moderation', 16)->default('pending'); // pending | approved | rejected
            $table->string('status', 16)->default('open'); // open | planned | building | released | declined
            $table->text('status_note')->nullable(); // public note shown with the status
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedInteger('votes_count')->default(0); // cached, changed in the vote's transaction
            $table->foreignId('merged_into_id')->nullable()->constrained('feedback_posts')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete(); // internal card, never public
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['feedback_portal_id', 'number']);
            $table->index(['feedback_portal_id', 'moderation', 'status']);
        });

        Schema::create('feedback_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['feedback_post_id', 'portal_identity_id']);
        });

        Schema::create('feedback_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['feedback_post_id', 'portal_identity_id']);
        });

        // Moderator audit trail: who did what, when (names kept as text so renames/deletes stay readable)
        Schema::create('feedback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 40);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['feedback_portal_id', 'id']);
        });

        // One status mail per follower, post and status — a retry or a flip back never mails twice
        Schema::create('feedback_status_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->timestamp('created_at')->nullable();

            $table->unique(['feedback_post_id', 'portal_identity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_status_mails');
        Schema::dropIfExists('feedback_events');
        Schema::dropIfExists('feedback_subscriptions');
        Schema::dropIfExists('feedback_votes');
        Schema::dropIfExists('feedback_posts');
        Schema::dropIfExists('feedback_portals');
    }
};
