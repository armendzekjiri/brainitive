<?php

use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\FeedbackPortalController;
use App\Http\Controllers\PortalLoginController;
use App\Http\Middleware\ReadOnlySession;
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
// Anonymous read; submit / vote / follow need a signed-in identity (portal.identity). The
// unsubscribe link from status mails is signed and works without signing in.
Route::prefix('feedback/{portalSlug}')->where(['portalSlug' => '[a-z0-9-]{3,60}'])->controller(FeedbackPortalController::class)->group(function () {
    Route::get('/', 'index')->middleware('throttle:120,1,feedback.portal')->name('feedback.portal');
    Route::get('roadmap', 'roadmap')->middleware('throttle:120,1,feedback.roadmap')->name('feedback.roadmap');
    Route::get('similar', 'similar')->middleware([ReadOnlySession::class, 'throttle:60,1,feedback.similar'])->name('feedback.similar');
    Route::post('posts', 'store')->middleware(['portal.identity', 'throttle:10,1,feedback.posts.store'])->name('feedback.posts.store');
    Route::prefix('{number}')->whereNumber('number')->group(function () {
        Route::get('/', 'show')->middleware('throttle:120,1,feedback.post')->name('feedback.post');
        Route::post('vote', 'vote')->middleware(['portal.identity', 'throttle:60,1,feedback.vote'])->name('feedback.vote');
        Route::delete('vote', 'unvote')->middleware(['portal.identity', 'throttle:60,1,feedback.unvote'])->name('feedback.unvote');
        Route::post('follow', 'follow')->middleware(['portal.identity', 'throttle:60,1,feedback.follow'])->name('feedback.follow');
        Route::delete('follow', 'unfollow')->middleware(['portal.identity', 'throttle:60,1,feedback.unfollow'])->name('feedback.unfollow');
        // the link in status mails (signed, no sign-in): GET = confirm page, POST = unfollow
        Route::get('unfollow', 'unsubscribeShow')->middleware(['signed', 'throttle:30,1,feedback.unsubscribe'])->name('feedback.unsubscribe');
        Route::post('unfollow', 'unsubscribe')->middleware(['signed', 'throttle:30,1,feedback.unsubscribe.store'])->name('feedback.unsubscribe.store');
    });
});

// ── Ticket 2 (BRAI-73): client portal — /client/{slug}… ─────────────────────────
// portal.identity = a signed-in email; ClientPortalController adds "active member of THIS portal".
Route::prefix('client/{slug}')->where(['slug' => '[a-z0-9-]{3,60}'])->middleware('portal.identity')->controller(ClientPortalController::class)->group(function () {
    Route::get('/', 'show')->middleware('throttle:120,1,client-portal.show')->name('client-portal.show');
    Route::get('d/{deliverable}', 'deliverable')->whereUuid('deliverable')->middleware('throttle:120,1,client-portal.deliverable')->name('client-portal.deliverable');
    Route::get('d/{deliverable}/download', 'download')->whereUuid('deliverable')->middleware('throttle:30,1,client-portal.download')->name('client-portal.download');
    Route::post('d/{deliverable}/decision', 'decide')->whereUuid('deliverable')->middleware('throttle:20,1,client-portal.decide')->name('client-portal.decide');
    Route::post('d/{deliverable}/comments', 'comment')->whereUuid('deliverable')->middleware('throttle:20,1,client-portal.comments.store')->name('client-portal.comments.store');
});
