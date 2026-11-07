<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Developer API (v1.52.0): personal API keys for /api/v1 (App\Support\ApiKeys\ApiKeys). Only the sha256
| of a key is stored; `prefix` is the short visible start of it for lists ("brai_AbC123…").
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('prefix', 16);
            $table->char('token_hash', 64)->unique();
            // where: all | workspaces | projects | boards, with the chosen ids
            $table->string('scope_type', 16)->default('all');
            $table->json('scope_ids')->nullable();
            // what: {area: none|read|write}; delete is its own flag
            $table->json('abilities');
            $table->boolean('can_delete')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();
            $table->string('created_from_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
