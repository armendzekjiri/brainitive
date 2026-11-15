<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * My Week (v1.53.0): a plan line can be a free-form note (`work_type = note`, no `work_id`) with
     * its own title, colour dot and done state. Linked lines keep using `work_id`. A line without
     * `plan_date` is on the "Someday" list (no day yet).
     */
    public function up(): void
    {
        Schema::table('my_day_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('work_id')->nullable()->change();
            $table->date('plan_date')->nullable()->change();
            $table->string('title', 500)->nullable();
            $table->string('color', 20)->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        // the unique key above treats NULL dates as distinct: a task goes on Someday once (partial index, PG + SQLite)
        DB::statement('create unique index my_day_entries_someday_unique on my_day_entries (user_id, work_type, work_id) where plan_date is null and work_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists my_day_entries_someday_unique');
        DB::table('my_day_entries')->whereNull('work_id')->orWhereNull('plan_date')->delete();

        Schema::table('my_day_entries', function (Blueprint $table) {
            $table->dropColumn(['title', 'color', 'completed_at']);
            $table->unsignedBigInteger('work_id')->nullable(false)->change();
            $table->date('plan_date')->nullable(false)->change();
        });
    }
};
