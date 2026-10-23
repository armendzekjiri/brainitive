<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // IANA zone from the browser (e.g. "Europe/Berlin"); reminders are computed in it.
            $table->string('timezone', 64)->nullable()->after('calendar_view');
            // Daily "due today / overdue" summary for task lists.
            $table->boolean('task_digest')->default(true)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'task_digest']);
        });
    }
};
