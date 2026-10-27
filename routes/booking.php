<?php

use App\Http\Controllers\PublicBookingController;
use Illuminate\Support\Facades\Route;

/*
 * Public booking pages (v1.45.0, BRAI-158), no login. POSTs are CSRF-exempt (book/*: the page can be
 * embedded on other sites); every route has its own throttle key. /book/manage/… comes first:
 * "manage" is a reserved page address.
 */
Route::prefix('book/manage/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->group(function () {
    Route::get('/', [PublicBookingController::class, 'manage'])->middleware('throttle:60,1,booking.manage')->name('booking.manage');
    Route::get('slots', [PublicBookingController::class, 'manageSlots'])->middleware('throttle:60,1,booking.manage.slots')->name('booking.manage.slots');
    Route::post('cancel', [PublicBookingController::class, 'manageCancel'])->middleware('throttle:10,1,booking.manage.cancel')->name('booking.manage.cancel');
    Route::post('reschedule', [PublicBookingController::class, 'manageReschedule'])->middleware('throttle:10,1,booking.manage.reschedule')->name('booking.manage.reschedule');
});

Route::prefix('book/{publicSlug}')->where(['publicSlug' => '[a-z0-9-]{3,60}', 'typeSlug' => '[a-z0-9-]{1,60}'])->group(function () {
    Route::get('/', [PublicBookingController::class, 'show'])->middleware('throttle:120,1,booking.public')->name('booking.public');
    Route::get('{typeSlug}', [PublicBookingController::class, 'show'])->middleware('throttle:120,1,booking.public.type')->name('booking.public.type');
    Route::get('{typeSlug}/slots', [PublicBookingController::class, 'slots'])->middleware('throttle:60,1,booking.slots')->name('booking.slots');
    Route::post('{typeSlug}', [PublicBookingController::class, 'store'])->middleware('throttle:10,1,booking.store')->name('booking.store');
});
