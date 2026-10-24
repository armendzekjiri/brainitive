<?php

use App\Http\Controllers\AutomationController;
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

// ── Ticket 5: helpdesk SLA — helpdesks.sla.* ──────────────────────────────────
