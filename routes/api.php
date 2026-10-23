<?php

use App\Http\Controllers\CliController;
use App\Http\Controllers\Extension\CaptureController;
use App\Http\Controllers\Extension\ExtensionController;
use App\Http\Controllers\GithubWebhookController;
use App\Http\Controllers\LeadFormInboundController;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\RejectCaptureTokens;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
|--------------------------------------------------------------------------
| CLI / API Routes
|--------------------------------------------------------------------------
*/

// Public: issue a Passport personal access token from email + password
Route::post('/cli/login', [CliController::class, 'login'])->middleware('throttle:5,1,cli.login');

// Protected endpoints — require a valid Passport API token (Bearer)
// scopeBindings: {item} must belong to {taskList}, not just any list the user can manage.
Route::middleware(['auth:api', EnsureUserIsNotSuspended::class, RejectCaptureTokens::class])->scopeBindings()->group(function () {
    Route::get('/cli/me', [CliController::class, 'me']);
    Route::delete('/cli/logout', [CliController::class, 'logout']);

    Route::get('/cli/workspaces', [CliController::class, 'workspaces']);

    // Task lists across all accessible workspaces (optionally filter by workspace slug)
    Route::get('/cli/lists', [CliController::class, 'lists']);
    Route::get('/cli/lists/{taskList}', [CliController::class, 'showList']);
    Route::post('/cli/lists/{taskList}/items', [CliController::class, 'storeItem']);
    Route::patch('/cli/lists/{taskList}/items/{item}', [CliController::class, 'updateItem']);
    Route::delete('/cli/lists/{taskList}/items/{item}', [CliController::class, 'destroyItem']);
});

/*
|--------------------------------------------------------------------------
| Browser extension (OAuth token with the `capture` scope)
|--------------------------------------------------------------------------
| Everything resolves by numeric id and runs the same policies as the app.
*/
Route::prefix('ext/v1')
    ->middleware(['auth:api', EnsureUserIsNotSuspended::class, CheckToken::using('capture'), 'throttle:120,1,ext'])
    ->name('ext.')
    ->group(function () {
        Route::get('me', [ExtensionController::class, 'me'])->name('me');
        Route::get('destinations', [ExtensionController::class, 'destinations'])->name('destinations');
        Route::get('search', [ExtensionController::class, 'search'])->name('search');
        Route::get('my-work', [ExtensionController::class, 'myWork'])->name('my-work');

        Route::post('bookmarks/{bookmarkGroup:id}/items', [CaptureController::class, 'bookmarkItem'])->name('bookmarks.items.store');
        Route::post('boards/{board:id}/items', [CaptureController::class, 'issue'])->name('boards.items.store');
        Route::post('projects/{project:id}/pages', [CaptureController::class, 'page'])->name('projects.pages.store');
        Route::post('task-lists/{taskList:id}/items', [CaptureController::class, 'task'])->name('task-lists.items.store');
        Route::post('snippet-collections/{snippetCollection:id}/snippets', [CaptureController::class, 'snippet'])->name('snippet-collections.snippets.store');
    });

// GitHub webhooks: stateless, verified by the per-project HMAC secret (BRAI-61).
Route::post('/webhooks/github/{uuid}', GithubWebhookController::class)
    ->middleware('throttle:120,1,webhooks.github')
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->name('webhooks.github');

// Lead forms: other tools push leads (Bearer = the form's inbound token)
Route::post('/lead-forms/{uuid}/inbound', [LeadFormInboundController::class, 'store'])
    ->whereUuid('uuid')->middleware('throttle:60,1,lead-forms.inbound')->name('lead-forms.inbound');
