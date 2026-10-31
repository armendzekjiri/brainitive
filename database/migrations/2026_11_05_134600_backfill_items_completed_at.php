<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Done column retention (BRAI-346) hides done cards by their completed_at. Cards that reached a
 * done column through a path that didn't set it (created there, column flagged done later, MCP)
 * get their last update as the completion time — the closest thing we know. Data only, plain
 * query builder (PostgreSQL + SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('items')
            ->whereNull('completed_at')
            ->whereIn('column_id', DB::table('board_columns')->where('is_done_column', true)->select('id'))
            ->update(['completed_at' => DB::raw('coalesce(updated_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        // Nothing to undo: the backfilled dates can't be told apart from real ones.
    }
};
