<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A board's sprints (Scrum). Points are snapshotted at start/complete so velocity and
     * finished burndowns stay correct after issues move on.
     */
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('goal')->nullable();
            $table->string('status', 20)->default('planned'); // planned | active | completed
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('committed_points')->nullable();
            $table->unsignedInteger('committed_issues')->nullable();
            $table->unsignedInteger('completed_points')->nullable();
            $table->unsignedInteger('completed_issues')->nullable();
            $table->json('burndown')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['board_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sprints');
    }
};
