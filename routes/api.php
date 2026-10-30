<?php

use App\Http\Controllers\CliController;
use App\Http\Controllers\CollabInternalController;
use App\Http\Controllers\Extension\CaptureController;
use App\Http\Controllers\Extension\ExtensionController;
use App\Http\Controllers\GithubAppWebhookController;
use App\Http\Controllers\GithubWebhookController;
use App\Http\Controllers\InboundMailController;
use App\Http\Controllers\LeadFormInboundController;
use App\Http\Controllers\LiveInternalController;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\RejectCaptureTokens;
use App\Http\Middleware\VerifyCollabSecret;
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
        Route::post('boards/{board:id}/items', [CaptureController::class, 'issue'])->middleware('file.room')->name('boards.items.store'); // screenshot
        Route::post('projects/{project:id}/pages', [CaptureController::class, 'page'])->name('projects.pages.store');
        Route::post('task-lists/{taskList:id}/items', [CaptureController::class, 'task'])->name('task-lists.items.store');
        Route::post('snippet-collections/{snippetCollection:id}/snippets', [CaptureController::class, 'snippet'])->name('snippet-collections.snippets.store');
        // "Save to inbox" (BRAI-70): create only, the inbox is never readable with a capture token
        Route::post('captures', [CaptureController::class, 'inbox'])->name('captures.store');
    });

// GitHub webhooks: stateless, verified by the per-project HMAC secret (BRAI-61).
Route::post('/webhooks/github/{uuid}', GithubWebhookController::class)
    ->middleware('throttle:120,1,webhooks.github')
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->name('webhooks.github');

// The GitHub App's webhook (BRAI-61): one endpoint for every installation, verified with the app's
// webhook secret; routed by installation + repository to the project that follows the repository.
Route::post('/webhooks/github-app', GithubAppWebhookController::class)
    ->middleware('throttle:600,1,webhooks.github.app')
    ->name('webhooks.github.app');

// Lead forms: other tools push leads (Bearer = the form's inbound token)
Route::post('/lead-forms/{uuid}/inbound', [LeadFormInboundController::class, 'store'])
    ->whereUuid('uuid')->middleware('throttle:60,1,lead-forms.inbound')->name('lead-forms.inbound');

// Email → helpdesk through a forwarding address (BRAI-124): the provider posts each received mail.
// Postmark: basic auth / ?token=; Mailgun: HMAC signature + replay window. 404 until configured.
Route::post('/inbound-mail/postmark', [InboundMailController::class, 'postmark'])
    ->middleware('throttle:240,1,inbound-mail.postmark')->name('inbound-mail.postmark');
Route::post('/inbound-mail/mailgun', [InboundMailController::class, 'mailgun'])
    ->middleware('throttle:240,1,inbound-mail.mailgun')->name('inbound-mail.mailgun');

// Live co-editing (BRAI-54): the realtime server (realtime/) loads / stores Yjs state and re-checks
// access. Bearer = key derived from COLLAB_SECRET; 404 while live editing isn't configured. All calls
// come from the realtime server's one IP (a store per edited document every 2–10 s): a generous limit.
Route::prefix('internal/collab')
    ->middleware([VerifyCollabSecret::class, 'throttle:30000,1,internal.collab'])
    ->name('internal.collab.')
    ->group(function () {
        Route::get('documents/{document}', [CollabInternalController::class, 'show'])->name('documents.show');
        Route::put('documents/{document}', [CollabInternalController::class, 'store'])->name('documents.store');
        Route::post('access', [CollabInternalController::class, 'access'])->name('access');
        // Live channels (BRAI-151): which channels connected users may still use (every minute)
        Route::post('live/access', [LiveInternalController::class, 'access'])->name('live.access');
    });
