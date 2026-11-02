<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codex review (v1.49.0), finding 2: app sign-ins the user approved before "sign out everywhere" (password change /
 * reset, suspension, account takeover) must not mint tokens afterwards. The approvals live in the cache (not
 * enumerable per user), so each one carries this counter from the moment of approval; SignOutEverywhere bumps it
 * and AppDeviceSignIn refuses a mismatch under the user row lock. Additive: a NOT NULL column with a constant
 * default (no table rewrite on PostgreSQL 11+), nothing to backfill — every existing user starts at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('app_auth_generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('app_auth_generation');
        });
    }
};
