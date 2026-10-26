<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline sync (BRAI-82): every change a device made offline carries its own uuid. The outcome
 * is stored once per (user, uuid), so a retried request is answered from here and never applied
 * twice; later changes reference something created offline by that uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('type', 40);
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('status');
            // what was created / changed: lets later mutations resolve `target.ref`
            $table->string('entity_type', 20)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('response');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'client_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_mutations');
    }
};
