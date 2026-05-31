<?php

use App\Http\Controllers\CliController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CLI / API Routes
|--------------------------------------------------------------------------
*/

// Public: issue a Passport personal access token from email + password
Route::post('/cli/login', [CliController::class, 'login']);

// Protected endpoints — require a valid Passport API token (Bearer)
Route::middleware('auth:api')->group(function () {
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
