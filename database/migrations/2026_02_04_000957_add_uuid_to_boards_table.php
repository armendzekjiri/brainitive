<?php

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add UUID to workspaces
        Schema::table('workspaces', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Generate UUIDs for existing workspaces
        Workspace::query()->update(['uuid' => null]);
        foreach (Workspace::all() as $workspace) {
            $workspace->uuid = Str::uuid();
            $workspace->save();
        }

        // Make UUID non-nullable
        Schema::table('workspaces', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });

        // Add UUID to projects
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Generate UUIDs for existing projects
        Project::query()->update(['uuid' => null]);
        foreach (Project::all() as $project) {
            $project->uuid = Str::uuid();
            $project->save();
        }

        // Make UUID non-nullable
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });

        // Add UUID to boards
        Schema::table('boards', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Generate UUIDs for existing boards
        Board::query()->update(['uuid' => null]);
        foreach (Board::all() as $board) {
            $board->uuid = Str::uuid();
            $board->save();
        }

        // Make UUID non-nullable
        Schema::table('boards', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });

        // Add UUID to items
        Schema::table('items', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Generate UUIDs for existing items
        Item::query()->update(['uuid' => null]);
        foreach (Item::all() as $item) {
            $item->uuid = Str::uuid();
            $item->save();
        }

        // Make UUID non-nullable
        Schema::table('items', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
