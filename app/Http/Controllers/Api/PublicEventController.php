<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventCategory;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicEventResource;
use App\Models\Event;
use App\Models\Showtime;
use App\Models\ShowtimeSectionPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The public catalog: no login needed. Only published events with an upcoming showtime appear. */
class PublicEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::enum(EventCategory::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $events = Event::query()
            ->published()
            ->whereHas('showtimes', fn ($query) => $this->upcoming($query))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('title', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->when($filters['city'] ?? null, fn ($query, $city) => $query->whereHas(
                'showtimes',
                fn ($showtimes) => $this->upcoming($showtimes)->whereHas(
                    'venue',
                    fn ($venues) => $venues->where('city', 'ilike', $this->escapeLike($city))
                )
            ))
            ->addSelect([
                'next_showtime_at' => $this->upcoming(
                    Showtime::query()->selectRaw('min(starts_at)')->whereColumn('event_id', 'events.id')
                ),
                'min_price' => ShowtimeSectionPrice::query()
                    ->selectRaw('min(showtime_section_prices.price)')
                    ->join('showtimes', 'showtimes.id', '=', 'showtime_section_prices.showtime_id')
                    ->whereColumn('showtimes.event_id', 'events.id')
                    ->where('showtimes.status', ShowtimeStatus::Scheduled->value)
                    ->where('showtimes.starts_at', '>', now()),
            ])
            ->orderBy('next_showtime_at')
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString();

        return PublicEventResource::collection($events);
    }

    public function show(string $slug): PublicEventResource
    {
        $event = Event::query()->published()->where('slug', $slug)->firstOrFail();

        $event->load(['showtimes' => fn ($query) => $this->upcoming($query)
            ->with(['venue', 'sectionPrices.section'])
            ->withCount([
                'seats as seats_total',
                'seats as seats_available' => fn ($seats) => $seats->where('status', SeatStatus::Available->value),
            ])
            ->orderBy('starts_at')]);

        return PublicEventResource::make($event);
    }

    /** Scheduled showtimes that have not started yet. Works on queries and relations. */
    private function upcoming($query)
    {
        return $query
            ->where('status', ShowtimeStatus::Scheduled->value)
            ->where('starts_at', '>', now());
    }

    /** Stops "%" and "_" typed by a visitor from acting as wildcards in a LIKE search. */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
