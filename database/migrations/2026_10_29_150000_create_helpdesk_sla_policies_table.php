<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk SLA (BRAI-125, v1.40.0): one policy per helpdesk. Targets per priority, business hours,
 * pause columns and the warning threshold; maths in App\Support\Helpdesks\Sla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helpdesk_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // {"<priority id>"|"none": {"first_response": {"value": 4, "unit": "hours"}|null, "resolution": …|null}}
            $table->json('targets')->nullable();
            // null = 24/7; else {"days": [1..7 ISO], "from": "09:00", "to": "17:00"} (to < from = overnight)
            $table->json('business_hours')->nullable();
            $table->string('timezone', 64)->default('UTC');
            // columns that pause the resolution clock ("Waiting on customer")
            $table->json('pause_column_ids')->nullable();
            // warn at this share of a target (null = no warning)
            $table->unsignedTinyInteger('warn_percent')->nullable()->default(80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_sla_policies');
    }
};
