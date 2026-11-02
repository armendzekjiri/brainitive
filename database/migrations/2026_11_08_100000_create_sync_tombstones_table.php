<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App API offline sync (BRAI-425): what left a user's offline copy without leaving a row behind —
 * a capture deleted for good, work reassigned to someone else. Issues, tasks and task lists are
 * soft-deleted and need no row here. Read by `GET /api/v1/sync/changes`, pruned after 30 days
 * (older cursors start a full sync). Additive: nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tombstones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // the feed's change type: capture | work
            $table->string('entity_type', 20);
            // capture uuid | issue:<id> | task:<id>
            $table->string('entity_id', 64);
            // deleted | unassigned
            $table->string('reason', 20);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tombstones');
    }
};
