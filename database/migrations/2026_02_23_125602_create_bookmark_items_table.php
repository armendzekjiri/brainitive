<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookmark_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookmark_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['link', 'file', 'todo', 'note'])->default('link');
            $table->string('title')->nullable();
            $table->text('url')->nullable();
            $table->string('file_path')->nullable();
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bookmark_id', 'type']);
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookmark_items');
    }
};
