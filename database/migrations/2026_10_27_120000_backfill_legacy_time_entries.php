<?php

use App\Support\Billing\LegacyTimeBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time log (v1.38.0, BRAI-99): work logged before the ledger existed becomes `legacy` time entries
 * (no activity, billable, `items.time_spent` unchanged). `legacy_log_id` remembers the history
 * entry each row came from, so the backfill (also `php artisan time-entries:backfill-legacy`) can
 * run again without duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_log_id')->nullable()->unique();
        });

        LegacyTimeBackfill::run();
    }

    public function down(): void
    {
        // query builder: legacy rows never counted towards time_spent
        DB::table('time_entries')->where('source', 'legacy')->delete();

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropUnique(['legacy_log_id']);
            $table->dropColumn('legacy_log_id');
        });
    }
};
