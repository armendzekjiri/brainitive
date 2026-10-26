<?php

use App\Http\Controllers\MeetingController;
use App\Http\Middleware\ReadOnlySession;
use Illuminate\Support\Facades\Route;

/*
 * Online meetings from issues and docs (v1.42.0, BRAI-147). Included inside
 * `workspaces/{workspace}/projects/{project:slug}` with scopeBindings (auth + verified).
 */
Route::controller(MeetingController::class)->prefix('meetings')->group(function () {
    Route::get('options', 'options')->middleware(ReadOnlySession::class)->name('meetings.options');
    Route::get('/', 'index')->middleware(ReadOnlySession::class)->name('meetings.index');
    Route::post('/', 'store')->middleware('throttle:20,1,meetings.store')->name('meetings.store');
    Route::delete('{meeting}', 'destroy')->whereNumber('meeting')->middleware('throttle:30,1,meetings.destroy')->name('meetings.destroy');
    Route::post('{meeting}/notes', 'notes')->whereNumber('meeting')->middleware('throttle:20,1,meetings.notes')->name('meetings.notes');
});
