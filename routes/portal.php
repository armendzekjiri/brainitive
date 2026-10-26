<?php

use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\PortalLoginController;
use Illuminate\Support\Facades\Route;

/*
 * Portals for people outside the workspace (v1.42.0), loaded from web.php OUTSIDE the auth group.
 * Not under /portal/* — that prefix is the stateless, CSRF-exempt helpdesk ticket portal.
 * Contract: docs/ai/tasks/release-v1.42.0.md. Throttles name their own counter (RouteThrottleTest).
 */

// ── Foundation: guest sign-in (email → one-time link → confirm) ─────────────────
Route::get('guest/login', [PortalLoginController::class, 'create'])->name('guest.login');
Route::post('guest/login', [PortalLoginController::class, 'store'])->middleware('throttle:10,1,guest.login.store')->name('guest.login.store');
Route::get('guest/login/{token}', [PortalLoginController::class, 'show'])->middleware('throttle:30,1,guest.login.show')->where('token', '[A-Za-z0-9]{48}')->name('guest.login.show');
Route::post('guest/login/{token}', [PortalLoginController::class, 'consume'])->middleware('throttle:30,1,guest.login.consume')->where('token', '[A-Za-z0-9]{48}')->name('guest.login.consume');
Route::post('guest/logout', [PortalLoginController::class, 'destroy'])->name('guest.logout');

// ── Ticket 1 (BRAI-60): public feedback portal — /feedback/{slug}… ─────────────

// ── Ticket 2 (BRAI-73): client portal — /client/{slug}… ─────────────────────────
// portal.identity = a signed-in email; ClientPortalController adds "active member of THIS portal".
Route::prefix('client/{slug}')->where(['slug' => '[a-z0-9-]{3,60}'])->middleware('portal.identity')->controller(ClientPortalController::class)->group(function () {
    Route::get('/', 'show')->middleware('throttle:120,1,client-portal.show')->name('client-portal.show');
    Route::get('d/{deliverable}', 'deliverable')->whereUuid('deliverable')->middleware('throttle:120,1,client-portal.deliverable')->name('client-portal.deliverable');
    Route::get('d/{deliverable}/download', 'download')->whereUuid('deliverable')->middleware('throttle:30,1,client-portal.download')->name('client-portal.download');
    Route::post('d/{deliverable}/decision', 'decide')->whereUuid('deliverable')->middleware('throttle:20,1,client-portal.decide')->name('client-portal.decide');
    Route::post('d/{deliverable}/comments', 'comment')->whereUuid('deliverable')->middleware('throttle:20,1,client-portal.comments.store')->name('client-portal.comments.store');
});
