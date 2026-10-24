<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-price activities bill once per ticket (v1.38.0, BRAI-101): the first finalized report that
 * contains a ticket + fixed activity records a row here, and later reports show those entries as
 * "fixed price, already billed". The unique key (item, activity) is what refuses a second charge,
 * also when two admins finalize at the same time. Entries without a ticket (item_id null) bill once
 * per report and project — NULLs never collide in a unique index. Voiding a report deletes its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_fixed_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_report_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'billing_activity_id']);
            $table->index('billing_report_id');
            $table->index(['workspace_id', 'billing_activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_fixed_charges');
    }
};
