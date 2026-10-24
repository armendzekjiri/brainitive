<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk SLA (BRAI-125, v1.40.0): the first public agent reply and the SLA state of each ticket,
 * computed by App\Support\Helpdesks\Sla\TicketSla (never written by hand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('sla_first_response_due_at')->nullable();
            $table->timestamp('sla_first_response_warn_at')->nullable();
            $table->timestamp('sla_resolution_due_at')->nullable();
            $table->timestamp('sla_resolution_warn_at')->nullable();
            // resolution clock paused since (a pause column); business minutes of finished pauses
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_minutes')->default(0);
            // when the first missed target was due, and which targets were missed
            $table->timestamp('sla_breached_at')->nullable();
            $table->boolean('sla_first_response_breached')->default(false);
            $table->boolean('sla_resolution_breached')->default(false);

            // the checker: open tickets whose warning / due time has passed
            $table->index(['completed_at', 'sla_first_response_warn_at'], 'tickets_sla_first_response_idx');
            $table->index(['completed_at', 'sla_resolution_warn_at'], 'tickets_sla_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_sla_first_response_idx');
            $table->dropIndex('tickets_sla_resolution_idx');
            $table->dropColumn([
                'first_responded_at', 'sla_first_response_due_at', 'sla_first_response_warn_at', 'sla_resolution_due_at',
                'sla_resolution_warn_at', 'sla_paused_at', 'sla_paused_minutes', 'sla_breached_at',
                'sla_first_response_breached', 'sla_resolution_breached',
            ]);
        });
    }
};
