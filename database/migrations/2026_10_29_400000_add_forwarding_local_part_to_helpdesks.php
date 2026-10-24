<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BRAI-124 (v1.40.0): the local part of a helpdesk's forwarding address,
 * `<slug>-<random>` @ config('services.inbound_mail.domain'). Null until the Email tab first shows
 * it; regenerating replaces it, so the old address stops working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesks', function (Blueprint $table) {
            $table->string('forwarding_local_part', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('helpdesks', function (Blueprint $table) {
            $table->dropUnique(['forwarding_local_part']);
            $table->dropColumn('forwarding_local_part');
        });
    }
};
