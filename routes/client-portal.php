<?php

use App\Http\Controllers\ClientPortalTeamController;
use Illuminate\Support\Facades\Route;

/*
 * Client portal, team side (v1.42.0, BRAI-73), loaded inside `workspaces/{workspace}` →
 * `projects/{project:slug}` (scopeBindings) in web.php (auth + verified). Abilities: ClientPortalPolicy
 * (viewAny / manage / publish). Member and deliverable ids are looked up inside the project's portal by
 * the controller. The client side (/client/{slug}) is in routes/portal.php.
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 */
Route::prefix('client-portal')->controller(ClientPortalTeamController::class)->name('client-portal.manage.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/', 'update')->name('update');
    Route::post('members', 'invite')->middleware('throttle:30,1,client-portal.manage.members.store')->name('members.store');
    Route::delete('members/{member}', 'revoke')->whereNumber('member')->name('members.destroy');
    Route::post('deliverables', 'publish')->middleware('throttle:30,1,client-portal.manage.deliverables.store')->name('deliverables.store');
    Route::get('deliverables/{deliverable}', 'showDeliverable')->whereNumber('deliverable')->name('deliverables.show');
    Route::delete('deliverables/{deliverable}', 'withdraw')->whereNumber('deliverable')->name('deliverables.destroy');
    Route::post('deliverables/{deliverable}/comments', 'comment')->whereNumber('deliverable')->middleware('throttle:60,1,client-portal.manage.deliverables.comments.store')->name('deliverables.comments.store');
});
