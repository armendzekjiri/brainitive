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
        Schema::create('item_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('target_item_id')->constrained('items')->onDelete('cascade');
            $table->string('link_type'); // blocks, is_blocked_by, duplicates, is_duplicated_by, relates_to, etc.
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Unique constraint to prevent duplicate links
            $table->unique(['source_item_id', 'target_item_id', 'link_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_links');
    }
};
