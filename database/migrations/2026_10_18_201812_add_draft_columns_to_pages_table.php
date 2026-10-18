<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Published pages keep their live version in `content`/`name`/`icon`; unpublished
     * edits made while editing them are autosaved into the `draft_*` columns.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->longText('draft_content')->nullable()->after('content');
            $table->string('draft_name')->nullable()->after('draft_content');
            $table->text('draft_icon')->nullable()->after('draft_name');
            $table->timestamp('draft_updated_at')->nullable()->after('draft_icon');
            $table->foreignId('draft_updated_by')->nullable()->after('draft_updated_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('edit_revision')->default(0)->after('draft_updated_by');
            $table->unsignedInteger('current_version')->default(0)->after('edit_revision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropForeign(['draft_updated_by']);
            $table->dropColumn([
                'draft_content',
                'draft_name',
                'draft_icon',
                'draft_updated_at',
                'draft_updated_by',
                'edit_revision',
                'current_version',
            ]);
        });
    }
};
