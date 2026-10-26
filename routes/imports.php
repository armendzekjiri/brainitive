<?php

use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProjectExportController;
use App\Http\Middleware\ReadOnlySession;
use Illuminate\Support\Facades\Route;

/*
 * Bring your data (v1.41.0), loaded inside the `workspaces/{workspace}` group of web.php (auth + verified).
 * The lifecycle routes are shared by every importer; an importer that needs extra endpoints (e.g. the
 * project export download) adds them in its own section below. Contract: docs/ai/tasks/release-v1.41.0.md.
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 */
Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1,imports.store')->name('imports.store');
Route::get('imports/{importRun}', [ImportController::class, 'show'])->name('imports.show');
Route::post('imports/{importRun}/start', [ImportController::class, 'start'])->middleware('throttle:20,1,imports.start')->name('imports.start');
Route::get('imports/{importRun}/status', [ImportController::class, 'status'])->name('imports.status');
Route::delete('imports/{importRun}', [ImportController::class, 'destroy'])->name('imports.destroy');

// ── Ticket 4 (BRAI-81): project export (download) ─────────────────────────────
// Project admins queue an export; the ZIP is downloaded through a signed link (24 h) by its exporter only.
Route::get('projects/{project}/exports', [ProjectExportController::class, 'index'])->middleware(ReadOnlySession::class)->name('projects.export.index');
Route::post('projects/{project}/exports', [ProjectExportController::class, 'store'])->middleware('throttle:5,1,projects.export.store')->name('projects.export.store');
Route::get('exports/{projectExport}/download', [ProjectExportController::class, 'download'])->middleware('throttle:30,1,projects.export.download')->name('projects.export.download');
