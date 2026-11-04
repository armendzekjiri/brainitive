<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with Apple (BRAI-449): the linked Apple ID, as the id_token `sub` (stable for one Apple
 * developer team; never the email, which may be a relay address). See App\Support\Auth\AppleLogin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_id']);
            $table->dropColumn('apple_id');
        });
    }
};
