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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('helpdesk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('helpdesk_columns')->cascadeOnDelete();
            $table->foreignId('ticket_contact_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // e.g., "PROJ-T1"
            $table->string('subject');
            $table->text('description')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('item_priorities')->nullOnDelete();

            $table->integer('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('key');
            $table->index('position');
            $table->index(['helpdesk_id', 'column_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};