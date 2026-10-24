<?php

namespace Tests\Concerns;

use App\Models\Event;
use App\Models\Showtime;
use App\Models\Venue;
use DateTimeInterface;

/**
 * Builds events the public can see. Use it together with CreatesOrganizerFixtures,
 * whose makeOrganizer(), makeVenueFor() and makeShowtimeFor() helpers it relies on.
 */
trait CreatesPublishedEvents
{
    /**
     * A published event with one showtime a week from now and all of its seats generated
     * (9 seats: VIP 150000, Regular 75000), or at $startsAt when given.
     *
     * @param  array<string, mixed>  $eventAttributes
     * @return array{0: Event, 1: Showtime}
     */
    protected function publishedEventWithShowtime(
        array $eventAttributes = [],
        ?Venue $venue = null,
        ?DateTimeInterface $startsAt = null,
    ): array {
        $venue ??= $this->makeVenueFor($this->makeOrganizer());

        $event = Event::factory()->published()->create([
            'organizer_id' => $venue->organizer_id,
            ...$eventAttributes,
        ]);

        $showtime = $this->makeShowtimeFor($event, $venue);

        if ($startsAt !== null) {
            $showtime->update(['starts_at' => $startsAt]);
        }

        return [$event, $showtime];
    }
}
