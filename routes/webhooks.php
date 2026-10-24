<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\ReadOnlySession;
use Illuminate\Support\Facades\Route;

/*
 * Outgoing webhooks (v1.39.0, ticket 3), loaded inside the `workspaces/{workspace}` group of web.php
 * (auth + verified). Workspace admins only: Gate::authorize('update', $workspace).
 * Route names: webhooks.* (index, store, update, destroy, test, deliveries, redeliver).
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 * Contract: docs/ai/tasks/release-v1.39.0.md.
 */

// ── Ticket 3: outgoing webhooks ───────────────────────────────────────────────
Route::controller(WebhookController::class)->group(function () {
    Route::get('webhooks', 'index')->name('webhooks.index');
    Route::post('webhooks', 'store')->middleware('throttle:30,1,webhooks.store')->name('webhooks.store');
    Route::put('webhooks/{webhook}', 'update')->whereNumber('webhook')->middleware('throttle:60,1,webhooks.update')->name('webhooks.update');
    Route::delete('webhooks/{webhook}', 'destroy')->whereNumber('webhook')->name('webhooks.destroy');
    // the secret never travels in Inertia props: revealed / rotated on request (JSON, no-store)
    Route::post('webhooks/{webhook}/secret', 'secret')->whereNumber('webhook')->middleware('throttle:20,1,webhooks.secret')->name('webhooks.secret');
    Route::post('webhooks/{webhook}/rotate', 'rotate')->whereNumber('webhook')->middleware('throttle:10,1,webhooks.rotate')->name('webhooks.rotate');
    Route::post('webhooks/{webhook}/test', 'test')->whereNumber('webhook')->middleware('throttle:10,1,webhooks.test')->name('webhooks.test');
    Route::get('webhooks/{webhook}/deliveries', 'deliveries')->whereNumber('webhook')->middleware(ReadOnlySession::class)->name('webhooks.deliveries');
    Route::post('webhooks/{webhook}/deliveries/{delivery}/redeliver', 'redeliver')->whereNumber(['webhook', 'delivery'])->middleware('throttle:20,1,webhooks.redeliver')->name('webhooks.redeliver');
});
