<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codex web review L (v1.49.0): an archived page that was a private draft when it was archived stays private to its
 * author and admins. Additive: NOT NULL default false (no table rewrite on PostgreSQL 11+); existing archived pages
 * keep today's visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('archived_private')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('archived_private');
        });
    }
};
