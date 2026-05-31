<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bookmarks ALTER COLUMN icon TYPE VARCHAR(500)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE bookmarks MODIFY icon VARCHAR(500) NULL');
        }
        // SQLite does not enforce varchar length, so no schema change is needed.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bookmarks ALTER COLUMN icon TYPE VARCHAR(10)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE bookmarks MODIFY icon VARCHAR(10) NULL');
        }
        // SQLite does not enforce varchar length, so no schema change is needed.
    }
};
