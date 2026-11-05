<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with Apple: the refresh token from Apple's code exchange (encrypted cast on User), kept
 * only to revoke the authorization when the account is deleted (App Store guideline 5.1.1(v); see
 * App\Jobs\RevokeAppleToken). `text`: the ciphertext is far longer than the token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('apple_refresh_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('apple_refresh_token');
        });
    }
};
