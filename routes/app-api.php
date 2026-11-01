<?php

use App\Http\Controllers\AppApi\AuthController;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| App API v1 — desktop and mobile apps (BRAI-380)
|--------------------------------------------------------------------------
| Loaded in bootstrap/app.php: prefix /api/v1, names `app.v1.*`, group `app-api` (JSON, throttle,
| no session, no CSRF, no CORS). Separate from the MCP / CLI / extension APIs: only `app` tokens
| (App\Support\AppApi\AppTokens) pass `auth:app`.
|
| Every endpoint: Form Request in, Resource out, policies, tests incl. "Passport token → 401",
| OpenAPI regenerated, a row in docs/ai/API.md, and the `## API` section on its mobile card.
| Recipe: docs/ai/skills/add-app-api-endpoint.md
*/

// ── Instance (public) ───────────────────────────────────────────────────────────────────────

// ── Auth (public): code exchange, refresh ────────────────────────────────────────────────────

Route::middleware('throttle:app-auth')->group(function () {
    Route::post('auth/exchange', [AuthController::class, 'exchange'])->name('auth.exchange');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
});

Route::middleware(['auth:app', EnsureUserIsNotSuspended::class])->group(function () {

    // ── Auth: sign out, webview session ──────────────────────────────────────────────────────

    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/web-session', [AuthController::class, 'webSession'])->name('auth.web-session');

    // ── Account ───────────────────────────────────────────────────────────────────────────────

    // ── Devices ───────────────────────────────────────────────────────────────────────────────

    // ── Invitations & onboarding ──────────────────────────────────────────────────────────────

});
