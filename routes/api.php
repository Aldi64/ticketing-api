<?php

use App\Http\Controllers\Api\Admin\EventReviewController as AdminEventReviewController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Organizer\EventController as OrganizerEventController;
use App\Http\Controllers\Api\Organizer\ShowtimeController as OrganizerShowtimeController;
use App\Http\Controllers\Api\Organizer\VenueController as OrganizerVenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
    Route::post('{id}/read', [NotificationController::class, 'read'])->name('read');
});

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

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'role:admin'])
    ->group(function () {
        Route::get('events', [AdminEventReviewController::class, 'index'])->name('events.index');
        Route::get('events/{event}', [AdminEventReviewController::class, 'show'])->name('events.show');
        Route::post('events/{event}/approve', [AdminEventReviewController::class, 'approve'])->name('events.approve');
        Route::post('events/{event}/reject', [AdminEventReviewController::class, 'reject'])->name('events.reject');
    });
