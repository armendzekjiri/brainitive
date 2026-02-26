<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snippet_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('uuid')->unique();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 10)->nullable()->default('📋');
            $table->string('visibility', 20)->default('private'); // private | public
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'position']);
        });

        Schema::create('snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('snippet_collections')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('uuid')->unique();
            $table->string('slug');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('language', 50)->default('plaintext');
            $table->longText('content')->nullable();
            $table->string('visibility', 20)->default('inherit'); // inherit | private | public
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'slug']);
            $table->index(['collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snippets');
        Schema::dropIfExists('snippet_collections');
    }
};
