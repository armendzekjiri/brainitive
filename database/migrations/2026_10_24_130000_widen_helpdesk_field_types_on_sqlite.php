<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 2026_03_01_093649 allowed radio / date / checkboxes on MySQL and PostgreSQL, but SQLite
     * kept the original enum CHECK, so those fields failed to save there (BRAI-92). Turn the
     * column into a plain string on SQLite; the app validates the type.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('helpdesk_fields', function (Blueprint $table) {
            $table->string('type', 50)->change();
        });
    }

    public function down(): void
    {
        // Nothing to undo: a string column accepts everything the enum did.
    }
};
