<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codex web review C (v1.49.0): `editor_uploads` records every stored workspace file without a size column of its
 * own — workspace logos / headers, bookmark icons and files, project and item-type icons, lead-form images,
 * whiteboard previews and temporary icons — not only rich-text uploads. `kind` says which (null = an editor
 * upload, as before). Additive: a nullable column, no rewrite; `files:backfill-usage` records existing files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editor_uploads', function (Blueprint $table) {
            $table->string('kind', 32)->nullable()->after('size');
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('editor_uploads', function (Blueprint $table) {
            $table->dropIndex(['kind', 'created_at']);
            $table->dropColumn('kind');
        });
    }
};
