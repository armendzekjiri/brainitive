<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmark_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookmark_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('viewer'); // viewer | editor
            $table->timestamps();

            $table->unique(['bookmark_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmark_collaborators');
    }
};
