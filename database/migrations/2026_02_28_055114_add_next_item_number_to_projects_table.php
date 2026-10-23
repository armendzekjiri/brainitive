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
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('next_item_number')->default(1)->after('settings');
        });

        // Initialize next_item_number for existing projects based on highest item key
        $projects = DB::table('projects')->get();
        foreach ($projects as $project) {
            $lastItem = DB::table('items')
                ->join('boards', 'items.board_id', '=', 'boards.id')
                ->where('boards.project_id', $project->id)
                ->orderByDesc('items.id')
                ->select('items.key')
                ->first();

            if ($lastItem && $lastItem->key) {
                // Extract number from key, e.g., ARME-23 -> 23
                $parts = explode('-', $lastItem->key);
                if (count($parts) > 1) {
                    $number = (int) end($parts);
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update(['next_item_number' => $number + 1]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('next_item_number');
        });
    }
};
