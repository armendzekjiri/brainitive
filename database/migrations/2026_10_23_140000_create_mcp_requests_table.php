<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency for MCP write tools: an AI client that retries a write with the same
 * request_id gets the original result instead of a second comment / move / plan edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 80);
            $table->string('request_id', 100);
            $table->char('payload_hash', 64);
            $table->json('result');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'tool', 'request_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_requests');
    }
};
