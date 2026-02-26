<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snippet_collections', function (Blueprint $table) {
            $table->string('icon', 2048)->nullable()->default('📋')->change();
        });
    }

    public function down(): void
    {
        Schema::table('snippet_collections', function (Blueprint $table) {
            $table->string('icon', 10)->nullable()->default('📋')->change();
        });
    }
};
