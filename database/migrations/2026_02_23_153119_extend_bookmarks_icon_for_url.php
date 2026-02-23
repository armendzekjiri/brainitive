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
        } else {
            DB::statement('ALTER TABLE bookmarks MODIFY icon VARCHAR(500) NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bookmarks ALTER COLUMN icon TYPE VARCHAR(10)');
        } else {
            DB::statement('ALTER TABLE bookmarks MODIFY icon VARCHAR(10) NULL');
        }
    }
};
