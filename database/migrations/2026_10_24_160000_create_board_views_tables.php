<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved board views (BRAI-78): a named set of the board's URL view params, personal or shared.
        Schema::create('board_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // owner of a personal view / creator of a shared one; shared views outlive their creator
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->boolean('is_shared')->default(false);
            $table->json('config'); // BoardViewConfig: { version, params }
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['board_id', 'is_shared']);
            $table->index(['user_id', 'board_id']);
        });

        // A user's default view per board (opening the board without filters applies it)
        Schema::create('board_view_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'board_id']);
            $table->index('board_view_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_view_defaults');
        Schema::dropIfExists('board_views');
    }
};
