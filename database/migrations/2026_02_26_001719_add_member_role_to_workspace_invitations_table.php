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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE workspace_invitations DROP CONSTRAINT IF EXISTS workspace_invitations_role_check');
            DB::statement("ALTER TABLE workspace_invitations ADD CONSTRAINT workspace_invitations_role_check CHECK (role::text = ANY (ARRAY['admin'::character varying, 'editor'::character varying, 'member'::character varying, 'viewer'::character varying]::text[]))");
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE workspace_invitations MODIFY COLUMN role ENUM('admin', 'editor', 'member', 'viewer') NOT NULL DEFAULT 'viewer'");
        }

        // SQLite: enum is stored as string; no constraint change needed if column accepts text
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE workspace_invitations DROP CONSTRAINT IF EXISTS workspace_invitations_role_check');
            DB::statement("ALTER TABLE workspace_invitations ADD CONSTRAINT workspace_invitations_role_check CHECK (role::text = ANY (ARRAY['admin'::character varying, 'editor'::character varying, 'viewer'::character varying]::text[]))");
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE workspace_invitations MODIFY COLUMN role ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'viewer'");
        }
    }
};
