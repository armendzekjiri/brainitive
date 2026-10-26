<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal document review (BRAI-80, v1.42.0): a review is an immutable snapshot of a page's draft
 * (name + content + sha256 hash + edit_revision) that the chosen reviewers approve or send back.
 * Inline comments anchor by text quote, so the stored page HTML never changes.
 * Contract: docs/ai/tasks/release-v1.42.0.md → "Ticket 3"; plan: docs/ai/tasks/feat-document-review.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // project admins: publish / publish changes need an approved review of the current draft
            $table->boolean('review_required')->default(false);
        });

        Schema::create('page_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name'); // kept on the history when the account is deleted
            $table->text('message')->nullable();
            // pending | approved | changes_requested (open) · cancelled | superseded | published (closed)
            $table->string('status', 24)->default('pending');
            $table->string('snapshot_name');
            $table->longText('snapshot_content')->nullable();
            $table->char('content_hash', 64); // Page::draftHash() at request time; differs later → stale
            $table->unsignedInteger('edit_revision')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'status']);
            $table->index('requested_by');
        });

        Schema::create('page_review_reviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('decision', 24)->default('pending'); // pending | approved | changes_requested
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['page_review_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('page_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('page_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->text('body');
            // text-quote anchor over the snapshot's whitespace-normalized text; null = page-level comment
            $table->text('quote')->nullable();
            $table->string('quote_prefix', 64)->nullable();
            $table->string('quote_suffix', 64)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['page_id', 'parent_id']);
            $table->index('page_review_id');
            $table->index('parent_id');
            $table->index('user_id');
            $table->index('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_comments');
        Schema::dropIfExists('page_review_reviewers');
        Schema::dropIfExists('page_reviews');
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('review_required');
        });
    }
};
