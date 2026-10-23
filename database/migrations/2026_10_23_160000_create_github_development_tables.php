<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret'); // encrypted
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('repositories')->nullable();
            $table->json('deployments')->nullable(); // "repo|environment" => last successful deploy time
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_event', 50)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('github_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_id', 100);
            $table->string('event', 50);
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['github_connection_id', 'delivery_id']);
        });

        Schema::create('item_dev_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // branch | commit | pull_request | deployment
            $table->string('repository', 200);
            $table->string('identifier', 255); // branch name, sha, PR number or environment
            $table->string('title', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('state', 30)->nullable();
            $table->string('author', 200)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'kind', 'repository', 'identifier']);
            $table->index(['github_connection_id', 'repository', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_dev_links');
        Schema::dropIfExists('github_deliveries');
        Schema::dropIfExists('github_connections');
    }
};
