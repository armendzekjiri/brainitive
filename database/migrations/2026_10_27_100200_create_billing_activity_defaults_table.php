<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket type → default activity (v1.38.0): when someone logs time on an issue of this type, the
 * activity is preselected (they can still change it). One mapping per workspace and item type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_activity_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_activity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'item_type_id']);
            $table->index('billing_activity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_activity_defaults');
    }
};
