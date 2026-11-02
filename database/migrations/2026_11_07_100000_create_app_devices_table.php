<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App API v1 (desktop / mobile, BRAI-380 / BRAI-428): one row per signed-in app install, holding
 * its current token pair. Own tokens, separate from Passport (MCP / CLI / browser extension).
 * Only sha256 hashes of tokens are stored. Sign-in codes live in the cache (AppAuthCodes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client', 16); // desktop | mobile
            $table->string('platform', 16); // macos | windows | linux | ios | android
            $table->string('name', 120);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->text('push_token')->nullable(); // encrypted cast; APNs / FCM later

            // The current pair. Refreshing replaces both and keeps the old refresh hash: seeing it
            // again is reuse (a stolen refresh token) → the device is revoked.
            $table->char('access_token_hash', 64)->nullable()->unique();
            $table->timestamp('access_expires_at')->nullable();
            $table->char('refresh_token_hash', 64)->nullable()->unique();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->char('previous_refresh_hash', 64)->nullable()->index();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable(); // sign_out | user | refresh_reuse | suspended | password_changed | limit
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_devices');
    }
};
