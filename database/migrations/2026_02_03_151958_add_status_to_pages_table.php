<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->after('content');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete()->after('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropForeign(['last_edited_by']);
            $table->dropColumn(['status', 'published_at', 'last_edited_by']);
        });
    }
};
