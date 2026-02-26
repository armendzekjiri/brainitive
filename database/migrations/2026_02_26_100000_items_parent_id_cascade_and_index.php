<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change parent_id FK to CASCADE so deleting a parent deletes its subtasks.
     * Add index for root items per board and children lookups.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')->references('id')->on('items')->onDelete('cascade');
            $table->index(['board_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'parent_id']);
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')->references('id')->on('items')->nullOnDelete();
        });
    }
};
