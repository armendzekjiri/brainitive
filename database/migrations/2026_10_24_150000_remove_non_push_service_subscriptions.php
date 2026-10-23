<?php

use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web push POSTs to the stored endpoint; subscriptions saved before endpoints were limited to
     * real push services (security hardening 2026-10-23) may point anywhere. Browsers re-subscribe
     * by themselves, so removing those rows loses nothing legitimate.
     */
    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            return;
        }

        DB::table('push_subscriptions')->select(['id', 'endpoint'])->orderBy('id')->chunkById(500, function ($rows) {
            $invalid = $rows->reject(fn ($row) => PushSubscriptionController::isPushService((string) $row->endpoint))->pluck('id');
            if ($invalid->isNotEmpty()) {
                DB::table('push_subscriptions')->whereIn('id', $invalid)->delete();
            }
        });
    }

    public function down(): void
    {
        // removed subscriptions are not restored
    }
};
