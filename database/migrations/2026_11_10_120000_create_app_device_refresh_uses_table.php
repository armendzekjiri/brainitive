<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codex review (v1.49.0), finding 7: every refresh token an app device has already used (sha256 only), so presenting
 * ANY of them again — not just the last one (`app_devices.previous_refresh_hash`) — is recognised as reuse and signs
 * the device out. Rows go with their device (cascade), when it is revoked, and after the refresh TTL (60 days: the
 * token would have expired anyway; `model:prune` daily). Additive: a new table, nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_device_refresh_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_device_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_device_refresh_uses');
    }
};
