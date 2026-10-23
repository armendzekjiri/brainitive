<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket keys (KEY-T1) come from the project key, which is only unique per workspace — but the
 * column was unique across the whole instance, so a second workspace with the same project key
 * got a 500 on its first ticket (found by the e2e suite, BRAI-96). Unique per helpdesk now,
 * like issue keys per project (2026_03_02_180000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique('tickets_key_unique');
            $table->unique(['helpdesk_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['helpdesk_id', 'key']);
            $table->unique('key');
        });
    }
};
