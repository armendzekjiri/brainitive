<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom fields for board issues (BRAI-77): defined per project, optionally per board.
        Schema::create('item_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20); // text | number | date | checkbox | select
            $table->json('board_ids')->nullable(); // null = every board of the project
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'archived_at']);
        });

        Schema::create('item_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_field_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Postgres doesn't index foreign keys on its own; options load per field, in order.
            $table->index(['item_field_id', 'position']);
        });

        // One typed column per field type; "no value" is no row, so 0 and false stay distinct.
        Schema::create('item_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_field_id')->constrained()->cascadeOnDelete();
            $table->string('value_text', 1000)->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->foreignId('option_id')->nullable()->constrained('item_field_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'item_field_id']);
            // Usage counts / cascades per field and per option. Filtering and sorting run on the
            // client for now; add (item_field_id, value_*) indexes with the first server-side query.
            $table->index('item_field_id');
            $table->index('option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_field_values');
        Schema::dropIfExists('item_field_options');
        Schema::dropIfExists('item_fields');
    }
};
