<?php

use App\Http\Controllers\HelpdeskSlaController;
use Illuminate\Support\Facades\Route;

/*
 * Automation (v1.40.0), loaded inside `workspaces/{workspace}` → `projects/{project:slug}` (scopeBindings)
 * in web.php (auth + verified). One section per ticket. Contract: docs/ai/tasks/release-v1.40.0.md.
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 */

// ── Ticket 1 (BRAI-63): workflow automation rules — automations.* ─────────────

// ── Ticket 2: recurring issues — recurring-issues.* ───────────────────────────

// ── Ticket 5: helpdesk SLA — helpdesks.sla.* ──────────────────────────────────
Route::get('helpdesks/{helpdesk:slug}/sla', [HelpdeskSlaController::class, 'show'])->name('helpdesks.sla.show');
Route::put('helpdesks/{helpdesk:slug}/sla', [HelpdeskSlaController::class, 'update'])->name('helpdesks.sla.update');
if (app()->environment('e2e')) {
    // e2e only: backdate a ticket + run app:check-helpdesk-sla (dev has no scheduler and no such route)
    Route::post('helpdesks/{helpdesk:slug}/sla/e2e-check', [HelpdeskSlaController::class, 'e2eCheck'])->name('helpdesks.sla.e2e-check');
}
