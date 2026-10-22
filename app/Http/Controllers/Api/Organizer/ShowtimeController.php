<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Actions\CreateShowtimeSeats;
use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\StoreShowtimeRequest;
use App\Http\Resources\ShowtimeResource;
use App\Models\Event;
use App\Models\Showtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ShowtimeController extends Controller
{
    public function index(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('view', $event);

        $showtimes = $event->showtimes()
            ->with(['venue', 'sectionPrices'])
            ->withCount($this->seatCounts())
            ->orderBy('starts_at')
            ->get();

        return ShowtimeResource::collection($showtimes);
    }

    public function store(StoreShowtimeRequest $request, Event $event, CreateShowtimeSeats $createSeats): JsonResponse
    {
        $prices = collect($request->validated('prices'))
            ->mapWithKeys(fn (array $row) => [(int) $row['seat_section_id'] => (int) $row['price']])
            ->all();

        // One transaction: if the prices are invalid the showtime is not left behind without seats.
        $showtime = DB::transaction(function () use ($request, $event, $createSeats, $prices) {
            $showtime = $event->showtimes()->create([
                'venue_id' => $request->validated('venue_id'),
                'starts_at' => $request->validated('starts_at'),
            ]);

            $createSeats->handle($showtime, $prices);

            return $showtime;
        });

        $showtime->load(['venue', 'sectionPrices'])->loadCount($this->seatCounts());

        return ShowtimeResource::make($showtime)->response()->setStatusCode(201);
    }

    public function cancel(Showtime $showtime): ShowtimeResource
    {
        Gate::authorize('update', $showtime);

        if ($showtime->status === ShowtimeStatus::Cancelled) {
            abort(409, 'This showtime is already cancelled.');
        }

        // Refunds are not supported yet, so a showtime with paid orders cannot be cancelled.
        if ($showtime->orders()->where('status', OrderStatus::Paid->value)->exists()) {
            abort(409, 'This showtime has paid orders. Refunds are not supported yet.');
        }

        $showtime->update(['status' => ShowtimeStatus::Cancelled]);
        $showtime->load(['venue', 'sectionPrices'])->loadCount($this->seatCounts());

        return ShowtimeResource::make($showtime);
    }

    /** @return array<int|string, mixed> */
    private function seatCounts(): array
    {
        return [
            'seats as seats_total',
            'seats as seats_available' => fn ($query) => $query->where('status', SeatStatus::Available->value),
            'seats as seats_held' => fn ($query) => $query->where('status', SeatStatus::Held->value),
            'seats as seats_sold' => fn ($query) => $query->where('status', SeatStatus::Sold->value),
        ];
    }
}
