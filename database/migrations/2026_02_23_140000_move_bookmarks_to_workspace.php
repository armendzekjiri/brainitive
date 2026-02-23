<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('bookmarks')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $project = DB::table('projects')->where('id', $row->project_id)->first();
                if ($project) {
                    DB::table('bookmarks')->where('id', $row->id)->update(['workspace_id' => $project->workspace_id]);
                }
            }
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'slug']);
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
            $table->unique(['workspace_id', 'slug']);
        });

    }

    public function down(): void
    {
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $firstWorkspace = DB::table('workspaces')->orderBy('id')->first();
        $firstProject = $firstWorkspace ? DB::table('projects')->where('workspace_id', $firstWorkspace->id)->orderBy('id')->first() : null;
        if ($firstProject) {
            DB::table('bookmarks')->update(['project_id' => $firstProject->id]);
        }

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'slug']);
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
            $table->unique(['project_id', 'slug']);
        });
    }
};
