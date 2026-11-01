<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification email digest (BRAI-429): one row per activity email waiting for its window
 * (`due_at`). SendNotificationDigest claims the due rows of one person + workspace (claim_token),
 * sends one email and deletes them. Leftovers are pruned after 7 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_notification_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64); // NotificationCatalog type
            $table->string('subject_type')->nullable(); // model whose `view` policy is re-checked at send time
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('dedupe_key', 40);
            $table->json('entry'); // label, title, subject_key, subject_title, context, body, url, mail_subject
            $table->timestamp('due_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'workspace_id', 'due_at']);
            $table->index('claim_token');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_notification_emails');
    }
};
