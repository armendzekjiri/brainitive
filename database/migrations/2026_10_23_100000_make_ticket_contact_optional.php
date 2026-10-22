<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents create tickets in the app without a customer contact, but the column was NOT
 * NULL: PostgreSQL / MySQL rejected every such ticket (500). SQLite in tests accepted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('ticket_contact_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('ticket_contact_id')->nullable(false)->change();
        });
    }
};
