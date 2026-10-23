<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_list_items', function (Blueprint $table) {
            // Remind this many minutes before the due date/time (0 = at due time, null = no reminder).
            $table->unsignedSmallInteger('reminder_minutes')->nullable()->after('recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('task_list_items', function (Blueprint $table) {
            $table->dropColumn('reminder_minutes');
        });
    }
};
