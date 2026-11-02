<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codex web review M (v1.49.0): a version recorded while its page was a private draft (whiteboard checkpoints,
 * restores) stays private to the page's author and admins after publishing. Additive: NOT NULL default false (no
 * table rewrite); `php artisan pages:mark-draft-versions` (dry run, `--force`) marks existing ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_versions', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('page_versions', function (Blueprint $table) {
            $table->dropColumn('is_draft');
        });
    }
};
