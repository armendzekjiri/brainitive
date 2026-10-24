<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved project status updates (BRAI-64): a person-chosen health status, the reviewed
        // narrative (claims with source refs like "item:12"), and the metric numbers at `as_of`.
        // Listed issues are stored as ids only and resolved for each reader, so a saved report
        // never keeps a title of something the reader can't open.
        Schema::create('project_health_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->text('summary')->nullable();
            $table->json('sections');
            $table->json('metrics');
            $table->timestamp('as_of');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('timezone', 64);
            $table->boolean('ai_assisted')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'created_at']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_health_reports');
    }
};
