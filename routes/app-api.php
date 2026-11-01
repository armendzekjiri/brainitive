<?php

use App\Http\Controllers\AppApi\AuthController;
use App\Http\Controllers\AppApi\ConnectedAppController;
use App\Http\Controllers\AppApi\DeviceAuthController;
use App\Http\Controllers\AppApi\DeviceController;
use App\Http\Controllers\AppApi\InstanceController;
use App\Http\Controllers\AppApi\InvitationController;
use App\Http\Controllers\AppApi\MeAvatarController;
use App\Http\Controllers\AppApi\MeController;
use App\Http\Controllers\AppApi\MemberController;
use App\Http\Controllers\AppApi\MyWorkController;
use App\Http\Controllers\AppApi\NavigationController;
use App\Http\Controllers\AppApi\NotificationController;
use App\Http\Controllers\AppApi\ProjectController;
use App\Http\Controllers\AppApi\SearchController;
use App\Http\Controllers\AppApi\WorkspaceController;
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
Route::get('instance', InstanceController::class)->name('instance');

// ── Auth (public): code exchange, refresh ────────────────────────────────────────────────────

Route::middleware('throttle:app-auth')->group(function () {
    Route::post('auth/exchange', [AuthController::class, 'exchange'])->name('auth.exchange');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
});

// ── Auth (public): device-code sign-in for the TV app (RFC 8628); confirmed at /app/device ──────
Route::post('auth/device', [DeviceAuthController::class, 'start'])->middleware('throttle:app-device-start')->name('auth.device');
Route::post('auth/device/token', [DeviceAuthController::class, 'token'])->middleware('throttle:app-device-poll')->name('auth.device.token');

Route::middleware(['auth:app', EnsureUserIsNotSuspended::class])->group(function () {

    // ── Auth: sign out, webview session ──────────────────────────────────────────────────────

    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/web-session', [AuthController::class, 'webSession'])->name('auth.web-session');

    // ── Account ───────────────────────────────────────────────────────────────────────────────
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me', [MeController::class, 'update'])->name('me.update');
    Route::post('me/avatar', [MeAvatarController::class, 'store'])->name('me.avatar.store');
    Route::delete('me/avatar', [MeAvatarController::class, 'destroy'])->name('me.avatar.destroy');

    // ── Devices ───────────────────────────────────────────────────────────────────────────────
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::patch('devices/current', [DeviceController::class, 'updateCurrent'])->name('devices.current.update');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device')->name('devices.destroy');

    // ── Invitations & onboarding ──────────────────────────────────────────────────────────────
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{1,128}')->name('invitations.show');
    // joining / creating need a verified address, as on the web (403 otherwise)
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{1,128}')->middleware('verified')->name('invitations.accept');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('verified')->name('workspaces.store');

    // Workspace content needs a verified address, as the whole web app does (403 otherwise; on
    // instances without verification every address counts as verified). /me stays open so the
    // apps can show "verify your email".
    Route::middleware('verified')->group(function () {

        // ── Workspaces ────────────────────────────────────────────────────────────────────────────
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('workspaces/{workspace:slug}', [WorkspaceController::class, 'show'])->name('workspaces.show');

        // ── Projects (addressed by id: slugs are unique per workspace only) ─────────────────────────
        Route::get('workspaces/{workspace:slug}/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('workspaces/{workspace:slug}/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('workspaces/{workspace:slug}/project-templates', [ProjectController::class, 'templates'])->name('projects.templates');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');

        // ── Members (role changes and removals: web only) ──────────────────────────────────────
        Route::get('workspaces/{workspace:slug}/members', [MemberController::class, 'workspace'])->name('members.workspace');
        Route::get('projects/{project}/members', [MemberController::class, 'project'])->whereNumber('project')->name('members.project');
        Route::post('workspaces/{workspace:slug}/invitations', [MemberController::class, 'invite'])->name('members.invite');

        // ── Navigation (the web sidebar; recent destinations live on the device) ─────────────────
        Route::get('me/navigation', [NavigationController::class, 'show'])->name('navigation.show');
        Route::put('me/pins', [NavigationController::class, 'updatePins'])->name('navigation.pins');

        // ── Search (SearchService: the web's search, MCP and the extension share it) ─────────────
        Route::get('search', SearchController::class)->name('search');

        // ── Notifications (NotificationInbox, shared with the web bell) ─────────────────────────
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('notifications/{id}/archive', [NotificationController::class, 'archive'])->name('notifications.archive');
        Route::get('me/notification-preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');

        // ── My Work + My Day (AssignedWork, MyDayPlan, WorkCompletion: shared with the web) ─────────
        // {id} = issue:<id> | task:<id>, checked in the controller (no route constraint on writes)
        Route::get('me/work', [MyWorkController::class, 'index'])->name('work.index');
        Route::patch('me/work/{id}', [MyWorkController::class, 'update'])->name('work.update');
        Route::get('me/my-day', [MyWorkController::class, 'myDay'])->name('my-day.show');
        Route::post('me/my-day/items', [MyWorkController::class, 'addToMyDay'])->name('my-day.items.store');
        Route::patch('me/my-day/items/{id}', [MyWorkController::class, 'updateMyDayItem'])->name('my-day.items.update');
        Route::delete('me/my-day/items/{id}', [MyWorkController::class, 'removeFromMyDay'])->name('my-day.items.destroy');
        Route::post('me/my-day/carry-over', [MyWorkController::class, 'carryOver'])->name('my-day.carry-over');

        // ── Connected apps + "Download my data" (account deletion: web only) ────────────────────
        Route::get('me/connected-apps', [ConnectedAppController::class, 'index'])->name('connected-apps.index');
        // {id} = device:<id> | oauth:<client uuid>, checked in the controller (a route constraint would
        // turn a malformed id into a 405 through web.php's GET fallback route)
        Route::delete('me/connected-apps/{id}', [ConnectedAppController::class, 'destroy'])->name('connected-apps.destroy');
        Route::get('me/data-export', [ConnectedAppController::class, 'export'])->name('data-export.show');
        Route::post('me/data-export', [ConnectedAppController::class, 'requestExport'])->middleware('throttle:5,1,app.data-export')->name('data-export.store');

    });

});
