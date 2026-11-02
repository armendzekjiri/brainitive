<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codex review (v1.49.0), findings 4 + 8: images and documents put into rich text (web editor `/upload/*`, App API
 * `POST /uploads`) count against the workspace's file storage. Until now they were files on the public disk with no
 * row, so WorkspaceUsageMeter couldn't see them. Additive: a new table; files uploaded before it are not backfilled
 * (no workspace is known for them — see docs/ai/tasks/release-v1.49.0.md → Codex security review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 255)->unique();
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['workspace_id', 'size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_uploads');
    }
};
