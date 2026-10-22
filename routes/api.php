<?php

use App\Http\Controllers\Api\AuthController;
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
