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

        if ($driver === 'sqlite') {
            Schema::table('workspace_user', function (Blueprint $table) {
                $table->enum('role', ['admin', 'editor', 'member', 'viewer'])->default('viewer')->change();
            });

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE workspace_user MODIFY COLUMN role ENUM('admin', 'editor', 'member', 'viewer') NOT NULL DEFAULT 'viewer'");

            return;
        }

        DB::statement('ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_role_check');
        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_role_check CHECK (role IN ('admin', 'editor', 'member', 'viewer'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        DB::statement("UPDATE workspace_user SET role = 'viewer' WHERE role = 'member'");

        if ($driver === 'sqlite') {
            Schema::table('workspace_user', function (Blueprint $table) {
                $table->enum('role', ['admin', 'editor', 'viewer'])->default('viewer')->change();
            });

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE workspace_user MODIFY COLUMN role ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'viewer'");

            return;
        }

        DB::statement('ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_role_check');
        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_role_check CHECK (role IN ('admin', 'editor', 'viewer'))");
    }
};
