<?php

use App\Http\Controllers\AutomationController;
use App\Http\Controllers\HelpdeskSlaController;
use App\Http\Controllers\RecurringIssueController;
use Illuminate\Support\Facades\Route;

/*
 * Automation (v1.40.0), loaded inside `workspaces/{workspace}` → `projects/{project:slug}` (scopeBindings)
 * in web.php (auth + verified). One section per ticket. Contract: docs/ai/tasks/release-v1.40.0.md.
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 */

// ── Ticket 1 (BRAI-63): workflow automation rules — automations.* ─────────────
Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
Route::get('automations/create', [AutomationController::class, 'create'])->name('automations.create');
Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
// "Test on an issue" (dry run, reads only)
Route::post('automations/preview', [AutomationController::class, 'preview'])
    ->middleware('throttle:60,1,automations.preview')->name('automations.preview');
Route::get('automations/{automationRule}', [AutomationController::class, 'edit'])->whereNumber('automationRule')->name('automations.edit');
Route::put('automations/{automationRule}', [AutomationController::class, 'update'])->whereNumber('automationRule')->name('automations.update');
Route::patch('automations/{automationRule}/toggle', [AutomationController::class, 'toggle'])->whereNumber('automationRule')->name('automations.toggle');
Route::delete('automations/{automationRule}', [AutomationController::class, 'destroy'])->whereNumber('automationRule')->name('automations.destroy');

// ── Ticket 2: recurring issues — recurring-issues.* ───────────────────────────
// JSON for the board header's "Recurring issues" dialog; RecurringIssueController checks createItems.
Route::prefix('boards/{board:slug}/recurring-issues')->group(function () {
    Route::get('/', [RecurringIssueController::class, 'index'])->name('recurring-issues.index');
    Route::post('/', [RecurringIssueController::class, 'store'])->name('recurring-issues.store');
    Route::post('preview', [RecurringIssueController::class, 'preview'])->middleware('throttle:120,1,recurring-issues.preview')->name('recurring-issues.preview');
    Route::put('{recurringIssue}', [RecurringIssueController::class, 'update'])->whereNumber('recurringIssue')->name('recurring-issues.update');
    Route::delete('{recurringIssue}', [RecurringIssueController::class, 'destroy'])->whereNumber('recurringIssue')->name('recurring-issues.destroy');
    Route::post('{recurringIssue}/pause', [RecurringIssueController::class, 'pause'])->whereNumber('recurringIssue')->name('recurring-issues.pause');
    Route::post('{recurringIssue}/resume', [RecurringIssueController::class, 'resume'])->whereNumber('recurringIssue')->name('recurring-issues.resume');
    // e2e only: run the scheduler for this board now (dev / production have no such route)
    if (app()->environment('e2e')) {
        Route::post('e2e-run', [RecurringIssueController::class, 'e2eRun'])->name('recurring-issues.e2e-run');
    }
});

// ── Ticket 5: helpdesk SLA — helpdesks.sla.* ──────────────────────────────────
Route::get('helpdesks/{helpdesk:slug}/sla', [HelpdeskSlaController::class, 'show'])->name('helpdesks.sla.show');
Route::put('helpdesks/{helpdesk:slug}/sla', [HelpdeskSlaController::class, 'update'])->name('helpdesks.sla.update');
if (app()->environment('e2e')) {
    // e2e only: backdate a ticket + run app:check-helpdesk-sla (dev has no scheduler and no such route)
    Route::post('helpdesks/{helpdesk:slug}/sla/e2e-check', [HelpdeskSlaController::class, 'e2eCheck'])->name('helpdesks.sla.e2e-check');
}
