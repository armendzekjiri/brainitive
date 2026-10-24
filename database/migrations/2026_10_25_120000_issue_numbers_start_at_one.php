<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * projects.next_item_number holds the last issue number used (Project::getNextItemKey bumps it
 * first), but it defaulted to 1 — blank projects started at KEY-2 (BRAI-97). Default 0 now, and
 * projects that never had an issue start over at 0. Projects with issues keep their counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('next_item_number')->default(0)->change();
        });

        DB::table('projects')
            ->where('next_item_number', 1)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('items')->whereColumn('items.project_id', 'projects.id'))
            ->update(['next_item_number' => 0]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('next_item_number')->default(1)->change();
        });
    }
};
