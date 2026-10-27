<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Download my data" (GDPR Art. 15 + 20, BRAI-156 follow-up): one row per request. The ZIP lives on
 * the private disk and is deleted 24 h after it was written (expires_at); rows are pruned after 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('queued'); // queued | running | ready | failed | expired
            $table->string('file_path')->nullable(); // private disk
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('counts')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
