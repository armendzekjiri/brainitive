<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_sticky_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('color', 20)->default('yellow');
            $table->integer('x')->default(100);
            $table->integer('y')->default(100);
            $table->integer('width')->default(220);
            $table->integer('font_size')->default(20);
            $table->text('content')->nullable(); // plain text content
            $table->timestamps();

            $table->index(['user_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_sticky_notes');
    }
};
