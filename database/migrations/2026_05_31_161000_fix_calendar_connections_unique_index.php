<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace the calendar_connections unique constraint with a partial unique
 * index that:
 *   1. ignores soft-deleted rows (WHERE deleted_at IS NULL) — so disconnecting
 *      and re-adding the same calendar/task list no longer hits a duplicate-key
 *      violation against the leftover soft-deleted row, and
 *   2. includes `kind` — so the same external id can exist as both a calendar
 *      and a tasks subscription, matching the updateOrCreate key used in
 *      CalendarConnectionController::store().
 *
 * The same (user_id, provider, kind, external_calendar_id) is still allowed
 * across different `account_email`s, so a calendar can be attached from
 * multiple Google accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Drop the original full unique index (created in the table migration).
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE calendar_connections DROP CONSTRAINT IF EXISTS cal_conn_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS cal_conn_unique');
        }

        DB::statement(
            'CREATE UNIQUE INDEX cal_conn_unique ON calendar_connections '
            .'(user_id, provider, kind, account_email, external_calendar_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::statement('DROP INDEX IF EXISTS cal_conn_unique');

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX cal_conn_unique ON calendar_connections '
                .'(user_id, provider, account_email, external_calendar_id)'
            );

            return;
        }

        DB::statement(
            'ALTER TABLE calendar_connections ADD CONSTRAINT cal_conn_unique '
            .'UNIQUE (user_id, provider, account_email, external_calendar_id)'
        );
    }
};
