<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A plan (Tom's Planner–style Gantt) is one JSON document: `data` holds the rows, bars and
     * dependencies, `settings` the view (zoom, columns). Saves are guarded by `edit_revision`.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description', 1000)->nullable();
            $table->longText('data')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('edit_revision')->default(0);
            $table->unsignedInteger('current_version')->default(0);
            $table->string('share_token', 64)->nullable()->unique();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
