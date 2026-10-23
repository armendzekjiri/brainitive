<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add project_id to items so we can scope keys per project
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('board_id')->constrained()->cascadeOnDelete();
        });

        // Backfill project_id for existing items based on their board's project_id
        // Use chunked updates to stay compatible with PostgreSQL (no UPDATE ... SET col = other_table.col without FROM)
        DB::table('items')->orderBy('id')->chunkById(100, function ($items) {
            foreach ($items as $item) {
                $projectId = DB::table('boards')->where('id', $item->board_id)->value('project_id');
                if ($projectId !== null) {
                    DB::table('items')
                        ->where('id', $item->id)
                        ->update(['project_id' => $projectId]);
                }
            }
        });

        // Replace global unique constraint on key with project-scoped uniqueness
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_key_unique');
            $table->unique(['project_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'key']);
            $table->unique('key');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
