<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\GitHubAuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1,register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('login/google', [GoogleAuthController::class, 'redirect'])
        ->name('login.google');

    Route::get('login/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('login.google.callback');

    Route::get('login/github', [GitHubAuthController::class, 'redirect'])
        ->name('login.github');

    Route::get('login/github/callback', [GitHubAuthController::class, 'callback'])
        ->name('login.github.callback');

    Route::get('login/microsoft', [MicrosoftAuthController::class, 'redirect'])
        ->middleware('throttle:30,1,login.microsoft')
        ->name('login.microsoft');

    Route::get('login/microsoft/callback', [MicrosoftAuthController::class, 'callback'])
        ->middleware('throttle:30,1,login.microsoft.callback')
        ->name('login.microsoft.callback');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1,password.email')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

// Email verification: open to guests (the link may be opened in another browser), but the URL must
// carry a valid, unexpired signature. `relative`: the signature ignores the host, so localhost vs
// 127.0.0.1 or a proxy in front doesn't break the link. Opening it never signs anyone in.
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed:relative', 'throttle:6,1,verification.verify'])
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1,verification.send')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
