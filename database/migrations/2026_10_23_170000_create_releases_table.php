<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('unreleased');
            $table->date('start_date')->nullable();
            $table->date('release_date')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->longText('notes')->nullable(); // private draft (sanitized HTML)
            $table->longText('published_notes')->nullable(); // public snapshot
            $table->timestamp('published_at')->nullable();
            $table->string('public_token', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'status']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('release_id')->nullable()->after('sprint_id')->constrained()->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('changelog_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('changelog_token'));
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
        });
        Schema::dropIfExists('releases');
    }
};
