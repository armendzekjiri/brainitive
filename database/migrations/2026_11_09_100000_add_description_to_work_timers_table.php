<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the user is working on, typed when starting the focus timer (App API `timer.start` sync change and
 * `POST /api/v1/me/timer/start`): the default note of the work log when the timer is stopped. Additive:
 * a nullable column, nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_timers', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_timers', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
