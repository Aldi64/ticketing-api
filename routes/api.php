<?php

use App\Http\Controllers\Api\Admin\EventReviewController as AdminEventReviewController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\Organizer\EventController as OrganizerEventController;
use App\Http\Controllers\Api\Organizer\ShowtimeController as OrganizerShowtimeController;
use App\Http\Controllers\Api\Organizer\VenueController as OrganizerVenueController;
use App\Http\Controllers\Api\PublicEventController;
use App\Http\Controllers\Api\SeatHoldController;
use App\Http\Controllers\Api\SeatMapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public catalog (no login)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:60,1')->group(function () {
    Route::get('events', [PublicEventController::class, 'index'])->name('events.index');
    Route::get('events/{slug}', [PublicEventController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('events.show');
    Route::get('showtimes/{showtime}/seats', [SeatMapController::class, 'show'])
        ->whereNumber('showtime')
        ->name('showtimes.seats');
});

/*
|--------------------------------------------------------------------------
| Midtrans webhook (public, protected by its signature)
|--------------------------------------------------------------------------
*/
Route::post('payments/midtrans/notification', MidtransWebhookController::class)
    ->name('payments.midtrans.notification');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Any logged-in user
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('{id}/read', [NotificationController::class, 'read'])->name('read');
    });

    Route::post('showtimes/{showtime}/hold', [SeatHoldController::class, 'store'])
        ->whereNumber('showtime')
        ->middleware('throttle:20,1')
        ->name('showtimes.hold.store');
    Route::delete('showtimes/{showtime}/hold', [SeatHoldController::class, 'destroy'])
        ->whereNumber('showtime')
        ->name('showtimes.hold.destroy');

    Route::post('showtimes/{showtime}/orders', [OrderController::class, 'store'])
        ->whereNumber('showtime')
        ->middleware('throttle:10,1')
        ->name('showtimes.orders.store');

    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('{code}', [OrderController::class, 'show'])->where('code', '[A-Za-z0-9]+')->name('show');
        Route::post('{code}/sync', [OrderController::class, 'sync'])->where('code', '[A-Za-z0-9]+')->middleware('throttle:30,1')->name('sync');
        Route::post('{code}/cancel', [OrderController::class, 'cancel'])->where('code', '[A-Za-z0-9]+')->name('cancel');
    });
});

/*
|--------------------------------------------------------------------------
| Organizer
|--------------------------------------------------------------------------
*/
Route::prefix('organizer')
    ->name('organizer.')
    ->middleware(['auth:sanctum', 'role:organizer'])
    ->group(function () {
        Route::apiResource('venues', OrganizerVenueController::class);
        Route::apiResource('events', OrganizerEventController::class);

        Route::post('events/{event}/submit', [OrganizerEventController::class, 'submit'])->name('events.submit');
        Route::post('events/{event}/poster', [OrganizerEventController::class, 'poster'])->name('events.poster');

        Route::get('events/{event}/showtimes', [OrganizerShowtimeController::class, 'index'])->name('events.showtimes.index');
        Route::post('events/{event}/showtimes', [OrganizerShowtimeController::class, 'store'])->name('events.showtimes.store');
        Route::post('showtimes/{showtime}/cancel', [OrganizerShowtimeController::class, 'cancel'])->name('showtimes.cancel');
    });

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'role:admin'])
    ->group(function () {
        Route::get('events', [AdminEventReviewController::class, 'index'])->name('events.index');
        Route::get('events/{event}', [AdminEventReviewController::class, 'show'])->name('events.show');
        Route::post('events/{event}/approve', [AdminEventReviewController::class, 'approve'])->name('events.approve');
        Route::post('events/{event}/reject', [AdminEventReviewController::class, 'reject'])->name('events.reject');
    });
