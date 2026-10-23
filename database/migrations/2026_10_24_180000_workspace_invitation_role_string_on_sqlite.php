<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 2026_02_26_001719 added the "member" invitation role on MySQL and PostgreSQL only; SQLite
     * kept the original enum CHECK (admin / editor / viewer), so inviting someone with the default
     * role "member" failed with a 500 on SQLite installs (found by the e2e suite, BRAI-96). Turn the
     * column into a plain string there; WorkspaceController validates the role.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('role', 20)->default('viewer')->change();
        });
    }

    public function down(): void
    {
        // Nothing to undo: a string column accepts everything the enum did.
    }
};
