<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live issue descriptions (BRAI-151, v1.44.0): the Yjs state of an issue's description, stored by the
 * realtime server through the internal API — like page_collab_states for documents. `epoch` grows when
 * the description is changed outside live editing (single-user save, MCP, a checkbox toggle), which
 * starts a new Y document `item.<id>.<epoch>`. Plan: docs/ai/tasks/feat-live-basics.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_collab_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('epoch')->default(0);
            $table->longText('state')->nullable(); // base64 Yjs update
            $table->unsignedInteger('state_bytes')->nullable();
            // Yjs state vector of the last description HTML a browser saved
            $table->json('html_vector')->nullable();
            $table->string('bootstrap_claim', 64)->nullable();
            $table->timestamp('bootstrap_claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_collab_states');
    }
};
