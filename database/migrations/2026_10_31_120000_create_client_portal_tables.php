<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client portal (v1.42.0, BRAI-73): per project, invited clients (guest identities) see an explicit
 * allowlist of frozen deliverables — a published page version or a plan snapshot — and approve /
 * request changes on that exact version, or comment. Contract: docs/ai/tasks/release-v1.42.0.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->unique(); // public path /client/{slug}, [a-z0-9-]
            $table->string('title');
            $table->text('welcome')->nullable(); // plain text
            $table->boolean('enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('client_portal_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['client_portal_id', 'portal_identity_id']);
            $table->index('portal_identity_id');
        });

        Schema::create('client_deliverables', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_portal_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // page | plan
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('page_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version'); // page version number / plan snapshot number
            $table->string('title');
            $table->json('content'); // frozen at publish: {html} or {plan: {description, data, settings}}
            $table->text('message')->nullable(); // note to the client, plain text
            $table->string('status', 20)->default('awaiting'); // awaiting | approved | changes_requested
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('client_deliverables')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['client_portal_id', 'kind', 'page_id']);
            $table->index(['client_portal_id', 'kind', 'plan_id']);
            // FK lookups when a page / version / plan / user / deliverable is deleted
            $table->index('page_id');
            $table->index('page_version_id');
            $table->index('plan_id');
            $table->index('published_by');
            $table->index('superseded_by_id');
        });

        Schema::create('client_deliverable_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_deliverable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identity_name'); // kept for the audit trail
            $table->string('identity_email');
            $table->string('decision', 20); // approved | changes_requested
            $table->text('note')->nullable();
            $table->unsignedInteger('version');
            $table->timestamp('created_at');

            $table->index(['client_deliverable_id', 'id']);
            $table->index('portal_identity_id');
        });

        Schema::create('client_deliverable_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_deliverable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->nullable()->constrained()->nullOnDelete(); // a client
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // a team member
            $table->string('author_name');
            $table->string('visibility', 10); // client (the client sees it) | internal (team only)
            $table->text('body'); // plain text
            $table->timestamps();

            $table->index(['client_deliverable_id', 'id']);
            $table->index('portal_identity_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_deliverable_comments');
        Schema::dropIfExists('client_deliverable_decisions');
        Schema::dropIfExists('client_deliverables');
        Schema::dropIfExists('client_portal_members');
        Schema::dropIfExists('client_portals');
    }
};
