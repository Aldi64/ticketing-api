<?php

namespace Tests\Concerns;

use App\Actions\CreateShowtimeSeats;
use App\Models\Event;
use App\Models\Seat;
use App\Models\SeatSection;
use App\Models\Showtime;
use App\Models\Venue;

trait CreatesShowtimeWithSeats
{
    /** @var array<int, int> seat_section_id => price used by the last makeShowtime() call */
    protected array $sectionPrices = [];

    /**
     * Builds a venue with a VIP section (row A) and a Regular section (the other rows),
     * a published event, and a showtime. With the defaults that is 10 seats.
     */
    protected function makeShowtime(int $rows = 2, int $seatsPerRow = 5, bool $generateSeats = true): Showtime
    {
        $venue = Venue::factory()->create();

        $vip = SeatSection::factory()->create(['venue_id' => $venue->id, 'name' => 'VIP']);
        $regular = SeatSection::factory()->create(['venue_id' => $venue->id, 'name' => 'Regular']);

        foreach (range(1, $rows) as $row) {
            foreach (range(1, $seatsPerRow) as $number) {
                Seat::factory()->create([
                    'venue_id' => $venue->id,
                    'seat_section_id' => $row === 1 ? $vip->id : $regular->id,
                    'row_label' => chr(64 + $row),
                    'seat_number' => $number,
                ]);
            }
        }

        $event = Event::factory()->published()->create(['organizer_id' => $venue->organizer_id]);

        $showtime = Showtime::factory()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'starts_at' => now()->addWeek(),
        ]);

        $this->sectionPrices = [
            $vip->id => 150000,
            $regular->id => 75000,
        ];

        if ($generateSeats) {
            app(CreateShowtimeSeats::class)->handle($showtime, $this->sectionPrices);
        }

        return $showtime;
    }
}
