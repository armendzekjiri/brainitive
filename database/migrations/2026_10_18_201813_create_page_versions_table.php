<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One immutable snapshot per publish. Already-published pages get their current
     * content as version 1 so history starts with what readers see today.
     */
    public function up(): void
    {
        Schema::create('page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('icon')->nullable();
            $table->longText('content')->nullable();
            $table->string('message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'version']);
        });

        DB::table('pages')
            ->where('type', 'page')
            ->where('status', 'published')
            ->orderBy('id')
            ->chunkById(200, function ($pages) {
                foreach ($pages as $page) {
                    DB::table('page_versions')->insert([
                        'page_id' => $page->id,
                        'version' => 1,
                        'name' => $page->name,
                        'icon' => $page->icon,
                        'content' => $page->content,
                        'message' => null,
                        'created_by' => $page->last_edited_by ?? $page->created_by,
                        'created_at' => $page->published_at ?? $page->updated_at,
                        'updated_at' => $page->published_at ?? $page->updated_at,
                    ]);
                    DB::table('pages')->where('id', $page->id)->update(['current_version' => 1]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_versions');
    }
};
