<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing check constraint and recreate with new values
        DB::statement("ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_role_check");
        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_role_check CHECK (role IN ('admin', 'editor', 'member', 'viewer'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original constraint
        DB::statement("UPDATE workspace_user SET role = 'viewer' WHERE role = 'member'");
        DB::statement("ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_role_check");
        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_role_check CHECK (role IN ('admin', 'editor', 'viewer'))");
    }
};
