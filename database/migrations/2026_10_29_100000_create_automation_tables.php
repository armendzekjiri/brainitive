<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow automation (v1.40.0, BRAI-63): per-project WHEN / IF / THEN rules and their run log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            // the person the rule runs as (whoever saved it last); their permissions are checked at run time
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 40);
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 20)->nullable();
            $table->timestamps();

            // the hook points' one cheap check: "any enabled rule for this project and trigger"
            $table->index(['project_id', 'trigger', 'enabled']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rule_version');
            $table->string('event_id', 120);
            $table->string('trigger', 40);
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label')->nullable();
            $table->json('context')->nullable();
            $table->string('status', 20)->default('queued');
            $table->text('message')->nullable();
            $table->json('results')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // idempotency: one run per rule version and event, however often the event is delivered
            $table->unique(['automation_rule_id', 'rule_version', 'event_id']);
            $table->index(['project_id', 'created_at']);
            $table->index(['automation_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
