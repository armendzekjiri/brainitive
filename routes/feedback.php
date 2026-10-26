<?php

use App\Http\Controllers\FeedbackModerationController;
use Illuminate\Support\Facades\Route;

/*
 * Feedback portal — team side (v1.42.0, BRAI-60), loaded inside `workspaces/{workspace}` →
 * `projects/{project:slug}` (scopeBindings) in web.php (auth + verified). Project admins only
 * (FeedbackModerationController checks ProjectPolicy::update). The public side is in routes/portal.php.
 * Contract: docs/ai/tasks/release-v1.42.0.md.
 */
Route::prefix('feedback')->name('projects.feedback.')->controller(FeedbackModerationController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('settings', 'updateSettings')->name('settings');
    Route::prefix('posts/{feedbackPost}')->whereNumber('feedbackPost')->group(function () {
        Route::post('moderate', 'moderate')->name('moderate');
        Route::put('status', 'updateStatus')->name('status');
        Route::post('merge', 'merge')->name('merge');
        Route::post('card', 'createCard')->middleware('throttle:30,1,projects.feedback.card')->name('card.create');
        Route::put('card', 'linkCard')->name('card.link');
        Route::delete('card', 'unlinkCard')->name('card.unlink');
    });
});
