<?php

use Database\Seeders\ItemPrioritySeeder;
use Database\Seeders\ItemTypeSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Installs deployed with `migrate` only (no db:seed) never got the global priorities, so the
 * priority picker was empty (BRAI-85). Both seeders are idempotent (firstOrCreate by slug).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ItemTypeSeeder)->run();
        (new ItemPrioritySeeder)->run();
    }

    public function down(): void
    {
        // Keep the rows: items may reference them.
    }
};
