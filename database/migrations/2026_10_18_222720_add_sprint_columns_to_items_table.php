<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `sprint_id` null = backlog. `backlog_position` orders the backlog and sprint lists
     * independently of the column order (`position`).
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('sprint_id')->nullable()->after('column_id')->constrained('sprints')->nullOnDelete();
            $table->unsignedInteger('backlog_position')->default(0)->after('position');
            $table->index(['board_id', 'sprint_id', 'backlog_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'sprint_id', 'backlog_position']);
            $table->dropForeign(['sprint_id']);
            $table->dropColumn(['sprint_id', 'backlog_position']);
        });
    }
};
