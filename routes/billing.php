<?php

use App\Http\Controllers\Billing\BillingDocumentController;
use App\Http\Controllers\Billing\TimesheetController;
use App\Http\Middleware\ReadOnlySession;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
 * Time tracking & client billing (v1.38.0), loaded inside the `workspaces/{workspace}` group of
 * web.php (auth + verified). Contract: docs/ai/tasks/release-v1.38.0-billing.md.
 * Everything under billing/ is for workspace admins: authorize with Gate::authorize('manageBilling', $workspace).
 * Each ticket adds its routes in its own section below (keeps parallel branches from colliding).
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 */

// Workspace → Billing (sidebar, admins): opens the first billing tab.
Route::get('billing', function (Workspace $workspace) {
    Gate::authorize('manageBilling', $workspace);
    foreach (['billing.reports.index', 'billing.time.index', 'billing.activities.index', 'billing.settings.edit'] as $name) {
        if (Route::has($name)) {
            return redirect()->route($name, $workspace);
        }
    }
    abort(404);
})->name('billing.index');

// ── BRAI-99 Time log: activities admin + ticket type defaults (agent: activities) ──────────────

// ── BRAI-99 Time log: log time, timer, my timesheet (agent: log-time) ─────────────────────────
// My timesheet (every workspace member, own entries; admins may change others' unlocked entries)
// and the member-facing activity options of every log form (Log work, timer, timesheet): never a rate.
Route::get('timesheet', [TimesheetController::class, 'index'])->name('timesheet.index');
Route::get('timesheet/activities', [TimesheetController::class, 'activities'])->middleware(ReadOnlySession::class)->name('timesheet.activities');
Route::get('timesheet/items', [TimesheetController::class, 'items'])->middleware(ReadOnlySession::class)->name('timesheet.items');
Route::post('timesheet/entries', [TimesheetController::class, 'store'])->middleware('throttle:120,1,timesheet.entries.store')->name('timesheet.entries.store');
Route::put('timesheet/entries/{timeEntry}', [TimesheetController::class, 'update'])->whereNumber('timeEntry')->middleware('throttle:120,1,timesheet.entries.update')->name('timesheet.entries.update');
Route::delete('timesheet/entries/{timeEntry}', [TimesheetController::class, 'destroy'])->whereNumber('timeEntry')->name('timesheet.entries.destroy');

// ── BRAI-100 Clients + team time overview + Excel export (agent: overview) ─────────────────────

// ── BRAI-101 Billing settings + documents: PDF / Excel / email (agent: documents) ─────────────
// Defined here so the report pages (agent: reports) can link to them before documents are built.
Route::get('billing/reports/{billingReport}/pdf', [BillingDocumentController::class, 'pdf'])->whereNumber('billingReport')->name('billing.reports.pdf');
Route::get('billing/reports/{billingReport}/xlsx', [BillingDocumentController::class, 'xlsx'])->whereNumber('billingReport')->name('billing.reports.xlsx');
Route::post('billing/reports/{billingReport}/send', [BillingDocumentController::class, 'send'])->whereNumber('billingReport')->middleware('throttle:10,1,billing.reports.send')->name('billing.reports.send');

// ── BRAI-101 Billing reports: builder, finalize, status (agent: reports) ───────────────────────
