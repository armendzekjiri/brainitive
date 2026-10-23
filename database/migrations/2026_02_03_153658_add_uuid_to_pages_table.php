<?php

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
        Schema::table('pages', function (Blueprint $table) {
            $table->uuid('uuid')->after('id')->nullable();
        });

        // Generate UUIDs for existing pages
        $pages = DB::table('pages')->whereNull('uuid')->get();
        foreach ($pages as $page) {
            DB::table('pages')
                ->where('id', $page->id)
                ->update(['uuid' => Str::uuid()->toString()]);
        }

        // Now make it unique
        Schema::table('pages', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
