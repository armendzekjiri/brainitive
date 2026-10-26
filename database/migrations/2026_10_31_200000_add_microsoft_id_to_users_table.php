<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with Microsoft (BRAI-148): the linked Microsoft account, as "{tid}:{oid}" from the
 * id_token (tenant id + object id: stable for personal and work accounts, never shared between
 * tenants). See App\Support\Auth\MicrosoftLogin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('microsoft_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['microsoft_id']);
            $table->dropColumn('microsoft_id');
        });
    }
};
