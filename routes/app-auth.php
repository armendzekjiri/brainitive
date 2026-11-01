<?php

use App\Http\Controllers\AppAuthController;
use App\Http\Controllers\AppDeviceApprovalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Native sign-in, browser side (desktop / mobile apps, BRAI-355 / BRAI-383)
|--------------------------------------------------------------------------
| Web routes (session, CSRF). The JSON half lives in routes/app-api.php (/api/v1/auth/*).
| Guests opening /app/authorize go to the login page (password, Google, GitHub, Microsoft) and
| come back through url.intended.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/app/authorize', [AppAuthController::class, 'show'])
        ->middleware('throttle:60,1,app.authorize')
        ->name('app.authorize');
    Route::post('/app/authorize', [AppAuthController::class, 'store'])
        ->middleware('throttle:30,1,app.authorize.store')
        ->name('app.authorize.store');

    // Device-code sign-in (TV, RFC 8628, BRAI-438): confirm the code the TV shows. GET never approves.
    Route::get('/app/device', [AppDeviceApprovalController::class, 'show'])
        ->middleware('throttle:app-device-entry')
        ->name('app.device');
    Route::post('/app/device', [AppDeviceApprovalController::class, 'store'])
        ->middleware('throttle:app-device-entry')
        ->name('app.device.store');
});

// The app's webview: a one-time code (POST /api/v1/auth/web-session) → a remembered web session
Route::get('/app/session/{code}', [AppAuthController::class, 'session'])
    ->where('code', '[A-Za-z0-9]{1,128}')
    ->middleware('throttle:30,1,app.session')
    ->name('app.session');
