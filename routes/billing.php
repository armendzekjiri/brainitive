<?php

use App\Http\Controllers\Billing\BillingDocumentController;
use App\Http\Controllers\Billing\BillingSettingsController;
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

// ── BRAI-100 Clients + team time overview + Excel export (agent: overview) ─────────────────────

// ── BRAI-101 Billing settings + documents: PDF / Excel / email (agent: documents) ─────────────
Route::get('billing/settings', [BillingSettingsController::class, 'edit'])->name('billing.settings.edit');
Route::put('billing/settings', [BillingSettingsController::class, 'update'])->name('billing.settings.update');
Route::get('billing/settings/logo', [BillingSettingsController::class, 'logo'])->name('billing.settings.logo');
Route::post('billing/settings/logo', [BillingSettingsController::class, 'storeLogo'])->middleware('throttle:20,1,billing.settings.logo.store')->name('billing.settings.logo.store');
Route::delete('billing/settings/logo', [BillingSettingsController::class, 'destroyLogo'])->name('billing.settings.logo.destroy');
// Defined here so the report pages (agent: reports) can link to them before documents are built.
Route::get('billing/reports/{billingReport}/pdf', [BillingDocumentController::class, 'pdf'])->whereNumber('billingReport')->middleware('throttle:30,1,billing.reports.pdf')->name('billing.reports.pdf');
Route::get('billing/reports/{billingReport}/xlsx', [BillingDocumentController::class, 'xlsx'])->whereNumber('billingReport')->middleware('throttle:30,1,billing.reports.xlsx')->name('billing.reports.xlsx');
Route::post('billing/reports/{billingReport}/send', [BillingDocumentController::class, 'send'])->whereNumber('billingReport')->middleware('throttle:billing-send')->name('billing.reports.send'); // 10/min per admin + daily cap per workspace (BillingServiceProvider)

// ── BRAI-101 Billing reports: builder, finalize, status (agent: reports) ───────────────────────
