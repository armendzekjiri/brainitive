<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import foundation (v1.41.0, BRAI-65): one row per import attempt, plus a source → created-record
 * map that makes runs resumable and idempotent. Every importer (Trello, Jira, Notion, Brainitive
 * project archive) uses these. Contract: docs/ai/tasks/release-v1.41.0.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // the target project (null for a restore, which creates the project itself)
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32); // trello | jira | notion | brainitive
            $table->string('status', 16)->default('uploaded'); // see ImportRun::STATUSES
            $table->string('file_path')->nullable(); // private disk, deleted after the run / retention
            $table->string('file_name')->nullable(); // the uploaded name, display only
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('source_fingerprint', 64)->nullable(); // sha256 of the upload: "imported before" warning
            $table->json('preview')->nullable(); // importer-defined dry-run summary
            $table->json('options')->nullable(); // what the user confirmed (mappings, include flags)
            $table->json('report')->nullable(); // {created: {...counts}, warnings: [...], unsupported: [...]}
            $table->json('result')->nullable(); // links to what was created: {project_id, board_id, page_id, ...}
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'source_fingerprint']);
        });

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40); // e.g. "card", "list", "issue", "page", "comment"
            $table->string('source_id', 191); // the source system's id (never a Brainitive id)
            $table->string('target_type', 40); // e.g. "item", "board_column", "page"
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['import_run_id', 'source_type', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('import_runs');
    }
};
