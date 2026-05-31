<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table) {
            // 'calendar' (default) for Google Calendar subscriptions,
            // 'tasks' for Google Tasks list subscriptions.
            $table->string('kind', 16)->default('calendar')->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
