<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email → helpdesk (v1.40.0, BRAI-79):
 * - helpdesk_mailboxes: the connected IMAP mailbox of a helpdesk (+ SMTP for replies), passwords encrypted;
 * - helpdesk_inbound_emails: every received message once (dedupe on helpdesk + Message-ID hash) with its
 *   result, for any transport (IMAP, forwarding address);
 * - ticket_email_messages: the Message-IDs of a ticket's email thread (in and out), used for threading;
 * - ticket_replies.email_*: the stable Message-ID and delivery state of a reply sent by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helpdesk_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('email_address');
            $table->string('from_name')->nullable();
            $table->string('imap_host');
            $table->unsignedInteger('imap_port')->default(993);
            $table->string('imap_encryption', 16)->default('ssl'); // ssl | starttls | none (local / e2e only)
            $table->string('imap_username');
            $table->text('imap_password'); // encrypted cast
            $table->string('imap_folder')->default('INBOX');
            $table->string('processed_folder')->nullable(); // move processed mail there; null = mark as read
            $table->string('smtp_mode', 16)->default('app'); // app | smtp
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 16)->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable(); // encrypted cast
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('enabled');
        });

        Schema::create('helpdesk_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helpdesk_id')->constrained()->cascadeOnDelete();
            $table->string('transport', 16); // imap | forwarding
            $table->char('message_hash', 64); // sha256 of the lower-cased Message-ID
            $table->text('message_id');
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 24); // InboundMail::RESULT_* or failed
            $table->string('reason', 64)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_reply_id')->nullable()->constrained('ticket_replies')->nullOnDelete();
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();

            $table->unique(['helpdesk_id', 'message_hash']);
            $table->index(['helpdesk_id', 'id']);
            $table->index(['helpdesk_id', 'from_email', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('ticket_email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_reply_id')->nullable()->constrained('ticket_replies')->cascadeOnDelete();
            $table->char('message_hash', 64);
            $table->text('message_id');
            $table->string('direction', 8); // in | out
            $table->timestamps();

            $table->index('message_hash');
            $table->index(['ticket_id', 'id']);
        });

        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->text('email_message_id')->nullable();
            $table->string('email_status', 16)->nullable(); // sending | sent | failed
            $table->string('email_error', 500)->nullable();
            $table->timestamp('email_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropColumn(['email_message_id', 'email_status', 'email_error', 'email_sent_at']);
        });
        Schema::dropIfExists('ticket_email_messages');
        Schema::dropIfExists('helpdesk_inbound_emails');
        Schema::dropIfExists('helpdesk_mailboxes');
    }
};
