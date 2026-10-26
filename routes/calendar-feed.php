<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ReadOnlySession;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * Private calendar feed (BRAI-142), loaded from web.php outside the auth group.
 * Throttles name their own counter (RouteThrottleTest).
 */

// Calendar apps (Apple, Outlook, Google) fetch this without a session: no cookies, no CSRF, no
// Inertia. The secret token in the URL is the only key; revoked / unknown → 404. The per-IP limit is
// loose (Google / Outlook fetch every subscriber's feed from a few shared IPs) and only caps guessing;
// each feed has its own limit in CalendarFeedController::ics (FETCHES_PER_MINUTE).
Route::get('calendar/feed/{token}.ics', [CalendarFeedController::class, 'ics'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:600,1,calendar-feed.ics')
    ->withoutMiddleware([
        EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class,
        ValidateCsrfToken::class, HandleInertiaRequests::class, EnsureUserIsNotSuspended::class,
    ])
    ->name('calendar-feed.ics');

// The owner manages the feed from the Calendar page ("Subscribe in other apps"), JSON only.
Route::middleware(['auth', 'verified'])->prefix('calendar/feed')->name('calendar-feed.')->group(function () {
    Route::get('/', [CalendarFeedController::class, 'show'])->middleware(ReadOnlySession::class)->name('show');
    Route::post('/', [CalendarFeedController::class, 'store'])->middleware('throttle:20,1,calendar-feed.store')->name('store');
    Route::patch('/', [CalendarFeedController::class, 'update'])->middleware('throttle:30,1,calendar-feed.update')->name('update');
    Route::post('reset', [CalendarFeedController::class, 'reset'])->middleware('throttle:10,1,calendar-feed.reset')->name('reset');
    Route::delete('/', [CalendarFeedController::class, 'destroy'])->name('destroy');
});
