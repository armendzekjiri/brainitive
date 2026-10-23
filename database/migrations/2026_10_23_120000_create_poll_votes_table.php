<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Votes on polls inside rich text (docs, issue descriptions). The poll itself (question,
 * options) lives in the document; votes live here so readers can vote without editing the
 * document, and each person has exactly one vote per poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);   // page | item
            $table->string('scope_id', 64);     // page uuid / item id
            $table->string('poll_id', 120);
            $table->string('option_uid', 40);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'poll_id', 'user_id']);
            $table->index(['scope_type', 'scope_id', 'poll_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
    }
};
