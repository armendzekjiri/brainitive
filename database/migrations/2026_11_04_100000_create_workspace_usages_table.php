<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud plans (v1.46.0): what each workspace uses, written nightly by usage:measure and read by
 * LimitGate (content / files) and the usage bars. Members and projects are counted live there too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('members')->default(0);
            $table->unsignedInteger('projects')->default(0);
            $table->unsignedBigInteger('content_bytes')->default(0);
            $table->unsignedBigInteger('file_bytes')->default(0);
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_usages');
    }
};
