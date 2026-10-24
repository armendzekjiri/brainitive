<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Linked content (BRAI-71): "source links to target" between issues, docs / whiteboards,
        // tickets and plans of one project. Types are stable keys (item, page, ticket, plan).
        // `derived` rows mirror plan rows / whiteboard cards linked to issues and are rewritten
        // by model hooks; `manual` rows are picked by people.
        Schema::create('content_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('origin', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'target_type', 'target_id', 'origin'], 'content_references_unique');
            $table->index(['target_type', 'target_id'], 'content_references_target_index');
            $table->index('project_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_references');
    }
};
