<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workload planning (BRAI-75): declared weekly capacity per person and project, per-week
 * overrides (leave, part weeks) and explicit allocations of an issue's effort to a week.
 * Weeks are stored as their Monday's calendar date (no time, no zone). Minutes everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workload_capacities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('weekly_minutes');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('workload_capacity_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedInteger('minutes');
            $table->string('note', 120)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'week_start']);
            $table->index('user_id');
        });

        Schema::create('workload_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedInteger('minutes');
            // the issue's original estimate when this allocation was last saved (change detection)
            $table->integer('estimate_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'user_id', 'week_start']);
            $table->index(['project_id', 'week_start']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workload_allocations');
        Schema::dropIfExists('workload_capacity_overrides');
        Schema::dropIfExists('workload_capacities');
    }
};
