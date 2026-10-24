<?php

use App\Http\Controllers\InsightsController;
use Illuminate\Support\Facades\Route;

/*
 * Admin insights (v1.39.0, ticket 4), loaded inside the `workspaces/{workspace}` group of web.php
 * (auth + verified). Workspace admins only: Gate::authorize('update', $workspace).
 * Route names: insights.* — `insights.index` redirects to the first tab. The sidebar link only
 * appears once `insights.index` exists (route().has). Contract: docs/ai/tasks/release-v1.39.0.md.
 */

// ── Ticket 4: admin insights ──────────────────────────────────────────────────
Route::controller(InsightsController::class)->group(function () {
    Route::get('insights', 'index')->name('insights.index');
    Route::get('insights/overview', 'overview')->name('insights.overview');
    Route::get('insights/team', 'team')->name('insights.team');
    Route::get('insights/projects', 'projects')->name('insights.projects');
    Route::get('insights/helpdesk', 'helpdesk')->name('insights.helpdesk');
    Route::get('insights/usage', 'usage')->name('insights.usage');
    Route::get('insights/{tab}/export', 'export')
        ->whereIn('tab', array_keys(InsightsController::TABS))
        ->middleware('throttle:30,1,insights.export')
        ->name('insights.export');
});
