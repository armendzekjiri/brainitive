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
        Schema::create('helpdesk_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helpdesk_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color')->nullable();
            $table->integer('position')->default(0);
            $table->integer('wip_limit')->nullable(); // Work in progress limit
            $table->boolean('is_done_column')->default(false);
            $table->timestamps();

            $table->unique(['helpdesk_id', 'slug']);
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helpdesk_columns');
    }
};
