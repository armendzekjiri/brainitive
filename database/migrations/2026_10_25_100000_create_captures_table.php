<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quick capture (BRAI-70): a private inbox of ideas, organized later into content.
        Schema::create('captures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // private to its owner: goes with the account, nobody else may keep it
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('url', 2048)->nullable();
            $table->string('intended_type', 20)->nullable(); // Capture::TYPES
            $table->string('source')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            // what the conversion created (Capture::TYPES) + a ResourceLocator snapshot of it
            $table->string('target_type', 20)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('target_location')->nullable();
            $table->timestamps();

            // every tab list / count / the sidebar badge filter by user + state, newest first
            $table->index(['user_id', 'archived_at', 'processed_at', 'captured_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captures');
    }
};
