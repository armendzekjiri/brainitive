<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Project templates saved by workspace admins (BRAI-72). Built-in templates live in code.
        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'name']);
            $table->index('created_by');
        });

        // Immutable snapshots: saving a template again adds a version, projects never follow.
        Schema::create('project_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('format'); // TemplateDocument::FORMAT at save time
            $table->longText('document'); // JSON with local refs and day offsets, never row ids
            $table->json('summary');
            $table->foreignId('source_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['project_template_id', 'version']);
            $table->index('source_project_id');
            $table->index('created_by');
        });

        // One row per "create project from template" request, so a retry never creates twice.
        Schema::create('project_template_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('template', 80); // builtin:<key> or template:<uuid>@<version>
            $table->string('status', 20); // running | completed | failed
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('user_id');
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_template_runs');
        Schema::dropIfExists('project_template_versions');
        Schema::dropIfExists('project_templates');
    }
};
