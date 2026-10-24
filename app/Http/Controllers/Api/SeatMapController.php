<?php

namespace App\Http\Controllers\Api;

use App\Enums\SeatStatus;
use App\Http\Controllers\Controller;
use App\Models\ShowtimeSeat;
use App\Support\BookableShowtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seat map the frontend draws. Public, but if the request carries a valid token the
 * user's own holds are marked, so their selection and countdown survive a page reload.
 */
class SeatMapController extends Controller
{
    public function show(Request $request, string $showtime): JsonResponse
    {
        $showtime = BookableShowtime::findOrFail($showtime);

        // Optional login: null for guests and for invalid or expired tokens.
        $user = $request->user('sanctum');

        $seats = ShowtimeSeat::query()
            ->join('seats', 'seats.id', '=', 'showtime_seats.seat_id')
            ->where('showtime_seats.showtime_id', $showtime->id)
            ->orderBy('seats.row_label')
            ->orderBy('seats.seat_number')
            ->select('showtime_seats.*')
            ->with('seat.section')
            ->get();

        $sections = $seats
            ->unique(fn (ShowtimeSeat $seat) => $seat->seat->seat_section_id)
            ->map(fn (ShowtimeSeat $seat) => [
                'id' => $seat->seat->seat_section_id,
                'name' => $seat->seat->section->name,
                'price' => $seat->price,
            ])
            ->values();

        return response()->json([
            'data' => [
                'showtime' => [
                    'id' => $showtime->id,
                    'starts_at' => $showtime->starts_at,
                    'event' => [
                        'id' => $showtime->event->id,
                        'title' => $showtime->event->title,
                        'slug' => $showtime->event->slug,
                    ],
                    'venue' => [
                        'id' => $showtime->venue->id,
                        'name' => $showtime->venue->name,
                        'city' => $showtime->venue->city,
                    ],
                ],
                'sections' => $sections,
                'seats' => $seats->map(fn (ShowtimeSeat $seat) => $this->seatPayload($seat, $user?->id))->values(),
            ],
            // The frontend uses this to run the hold countdown without trusting the visitor's clock.
            'meta' => ['server_time' => now()],
        ]);
    }

    /** @return array<string, mixed> */
    private function seatPayload(ShowtimeSeat $seat, ?int $userId): array
    {
        $activeHold = $seat->status === SeatStatus::Held && $seat->held_until?->isFuture();
        $mine = $activeHold && $userId !== null && (int) $seat->held_by === $userId;

        return [
            'id' => $seat->id,
            'row_label' => $seat->seat->row_label,
            'seat_number' => $seat->seat->seat_number,
            'section_id' => $seat->seat->seat_section_id,
            'price' => $seat->price,
            // An expired hold counts as available even before the cleanup job has run.
            'status' => match (true) {
                $seat->status === SeatStatus::Sold => 'sold',
                $activeHold => 'held',
                default => 'available',
            },
            'mine' => $mine,
            // Only the holder sees the deadline. Nobody sees who holds a seat.
            'held_until' => $mine ? $seat->held_until : null,
        ];
    }
}
