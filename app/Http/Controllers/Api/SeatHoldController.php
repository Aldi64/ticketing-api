<?php

namespace App\Http\Controllers\Api;

use App\Actions\HoldSeats;
use App\Actions\ReleaseSeats;
use App\Http\Controllers\Controller;
use App\Http\Requests\HoldSeatsRequest;
use App\Http\Requests\ReleaseSeatsRequest;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Support\BookableShowtime;
use Illuminate\Http\JsonResponse;

class SeatHoldController extends Controller
{
    /**
     * Holds the chosen seats for the logged-in user. All or nothing: if any seat is gone,
     * the response is a 409 listing the unavailable seat ids and nothing is held.
     */
    public function store(HoldSeatsRequest $request, string $showtime, HoldSeats $hold): JsonResponse
    {
        $showtime = BookableShowtime::findOrFail($showtime);

        $held = $hold->handle($request->user(), $showtime, $request->validated('seat_ids'));

        return response()->json([
            'data' => [
                'held_until' => $held->first()->held_until,
                'total' => $held->sum('price'),
                'seats' => $held->map(fn (ShowtimeSeat $seat) => [
                    'id' => $seat->id,
                    'label' => $seat->seat->label(),
                    'price' => $seat->price,
                ])->values(),
            ],
        ]);
    }

    /** Releases the user's own holds: the seats in "seat_ids", or all of them if it is left out. */
    public function destroy(ReleaseSeatsRequest $request, string $showtime, ReleaseSeats $release): JsonResponse
    {
        // Releasing does not need the showtime to be bookable, so a user can always give seats back.
        $showtime = Showtime::query()->findOrFail($showtime);

        $released = $release->handle($request->user(), $showtime, $request->validated('seat_ids'));

        return response()->json(['released' => $released]);
    }
}
