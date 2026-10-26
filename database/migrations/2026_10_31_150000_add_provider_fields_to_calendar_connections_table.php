<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns every calendar provider needs beyond Google's OAuth tokens (BRAI-140), so the
 * Outlook, CalDAV / iCloud and ICS providers don't need a migration of their own:
 *  - server_url: the CalDAV base URL, or the host of an ICS link (for display only).
 *  - credentials: encrypted JSON (CalDAV username + app password, the full ICS URL with
 *    its secret token, …). Hidden from serialization; cast `encrypted:array`.
 *  - sync_error / sync_error_at: the last failure loading this calendar, shown as a small
 *    warning on the calendar page; cleared on the next successful load.
 *
 * The partial unique index (user_id, provider, kind, account_email, external_calendar_id)
 * stays as it is: providers without an account email store a stable label in account_email
 * and a unique id per calendar in external_calendar_id (e.g. a hash of an ICS URL), so two
 * ICS links of one user are two rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table) {
            $table->string('server_url', 2048)->nullable()->after('external_calendar_color');
            $table->text('credentials')->nullable()->after('refresh_token');
            $table->text('sync_error')->nullable()->after('last_synced_at');
            $table->timestamp('sync_error_at')->nullable()->after('sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table) {
            $table->dropColumn(['server_url', 'credentials', 'sync_error', 'sync_error_at']);
        });
    }
};
