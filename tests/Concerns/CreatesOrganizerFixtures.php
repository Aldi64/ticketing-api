<?php

namespace Tests\Concerns;

use App\Actions\CreateShowtimeSeats;
use App\Actions\GenerateSeatGrid;
use App\Models\Event;
use App\Models\Showtime;
use App\Models\User;
use App\Models\Venue;

trait CreatesOrganizerFixtures
{
    protected function makeOrganizer(): User
    {
        return User::factory()->organizer()->create();
    }

    /** A venue with 9 seats: VIP row A (3 seats), Regular rows B and C (3 seats each). */
    protected function makeVenueFor(User $organizer): Venue
    {
        $venue = Venue::factory()->create(['organizer_id' => $organizer->id]);

        app(GenerateSeatGrid::class)->handle($venue, [
            ['name' => 'VIP', 'rows' => 1, 'seats_per_row' => 3],
            ['name' => 'Regular', 'rows' => 2, 'seats_per_row' => 3],
        ]);

        return $venue->load('sections');
    }

    /** @return array<int, array{seat_section_id: int, price: int}> request body for the "prices" field */
    protected function pricesFor(Venue $venue): array
    {
        return $venue->sections
            ->map(fn ($section) => [
                'seat_section_id' => $section->id,
                'price' => $section->name === 'VIP' ? 150000 : 75000,
            ])
            ->all();
    }

    /** A showtime a week from now, with its seats already generated. */
    protected function makeShowtimeFor(Event $event, Venue $venue): Showtime
    {
        $showtime = Showtime::factory()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'starts_at' => now()->addWeek(),
        ]);

        $prices = collect($this->pricesFor($venue))
            ->mapWithKeys(fn ($row) => [$row['seat_section_id'] => $row['price']])
            ->all();

        app(CreateShowtimeSeats::class)->handle($showtime, $prices);

        return $showtime;
    }
}
