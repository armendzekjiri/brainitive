<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lead Generation forms (BRAI-93): a form builder per project, each with its own leads board.
        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('public_slug', 60)->nullable()->unique(); // /f/{public_slug}
            $table->string('status', 20)->default('draft'); // draft | published
            $table->json('definition')->nullable(); // steps → fields + logic (LeadFormDefinition)
            $table->json('theme')->nullable();
            $table->json('settings')->nullable();
            $table->text('webhook_secret')->nullable(); // encrypted; signs outgoing webhooks
            $table->string('inbound_token', 64)->nullable()->unique(); // sha256 of the token shown once
            $table->unsignedInteger('next_lead_number')->default(0); // last number used
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'slug']);
            $table->index('created_by');
        });

        Schema::create('lead_form_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_form_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_done')->default(false);
            $table->timestamps();

            $table->unique(['lead_form_id', 'slug']);
            $table->index(['lead_form_id', 'position']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lead_form_id')->constrained()->cascadeOnDelete();
            // leads are moved before a column is deleted; never cascade them away
            $table->foreignId('column_id')->constrained('lead_form_columns')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('company')->nullable();
            $table->json('answers')->nullable(); // field id => value
            $table->string('source', 20)->default('form'); // form | webhook
            $table->json('meta')->nullable(); // utm, referrer — no IPs
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lead_form_id', 'number']);
            $table->index(['column_id', 'position']);
            $table->index('assigned_to');
        });

        // Analytics: anonymous counters per visit session, no personal data.
        Schema::create('lead_form_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_form_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 40);
            $table->string('type', 10); // view | start | step | submit
            $table->unsignedSmallInteger('step')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_form_id', 'type', 'created_at']);
            $table->index(['lead_form_id', 'session_id']);
            $table->index('created_at'); // pruning (LeadFormEvent::prunable)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_events');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_form_columns');
        Schema::dropIfExists('lead_forms');
    }
};
