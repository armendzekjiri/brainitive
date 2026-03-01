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
        Schema::create('helpdesk_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helpdesk_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // machine name / slug
            $table->string('label'); // display label
            $table->enum('type', ['text', 'rich_text', 'select', 'checkbox', 'file', 'number']);
            $table->json('options')->nullable(); // for select: ["Option A", "Option B"]
            $table->boolean('is_required')->default(false);
            $table->integer('position')->default(0);
            $table->boolean('is_visible_on_card')->default(false);
            $table->timestamps();

            $table->unique(['helpdesk_id', 'name']);
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helpdesk_fields');
    }
};