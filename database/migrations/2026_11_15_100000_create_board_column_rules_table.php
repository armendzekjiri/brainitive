<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow rules for boards (v1.52.0): at most one rule per target column — who may move cards into it
 * (members and / or roles), from which columns, and conditions. Members and "allowed from" columns are
 * pivots so deleting a user or a column cleans up through the foreign keys. Additive: new tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_column_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_column_id')->unique()->constrained('board_columns')->cascadeOnDelete();
            // role keys (WorkflowRules::ROLES); [] = no role listed
            $table->json('allowed_roles')->nullable();
            $table->boolean('require_assignee')->default(false);
            $table->boolean('require_subtasks_done')->default(false);
            $table->boolean('require_due_date')->default(false);
            $table->timestamps();

            $table->index('board_id');
        });

        Schema::create('board_column_rule_user', function (Blueprint $table) {
            $table->foreignId('board_column_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['board_column_rule_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('board_column_rule_from_column', function (Blueprint $table) {
            $table->foreignId('board_column_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_column_id')->constrained('board_columns')->cascadeOnDelete();

            $table->primary(['board_column_rule_id', 'board_column_id']);
            $table->index('board_column_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_column_rule_from_column');
        Schema::dropIfExists('board_column_rule_user');
        Schema::dropIfExists('board_column_rules');
    }
};
