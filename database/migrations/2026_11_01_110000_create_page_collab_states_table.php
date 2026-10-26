<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live co-editing of documents (BRAI-54, v1.43.0): the Yjs state of a page's draft, stored by the
 * realtime server (Hocuspocus) through the internal API. One row per page; `epoch` grows whenever the
 * draft is replaced outside live editing (discard, restore, MCP, a single-user save), which starts a
 * new Y document so an old one can never merge back. Plan: docs/ai/tasks/feat-live-docs.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_collab_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('epoch')->default(0);
            $table->longText('state')->nullable(); // base64 Yjs update (Y.encodeStateAsUpdate)
            $table->unsignedInteger('state_bytes')->nullable();
            // Yjs state vector ({client id: clock}) of the last draft HTML a browser saved
            $table->json('html_vector')->nullable();
            // first sync of an empty Y doc: one browser loads the draft HTML into it (20 s lease)
            $table->string('bootstrap_claim', 64)->nullable();
            $table->timestamp('bootstrap_claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_collab_states');
    }
};
