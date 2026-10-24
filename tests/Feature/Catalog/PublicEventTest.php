<?php

namespace Tests\Feature\Catalog;

use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Models\Event;
use App\Models\Showtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class PublicEventTest extends TestCase
{
    use CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    public function test_the_list_only_contains_published_events_with_an_upcoming_showtime(): void
    {
        $this->publishedEventWithShowtime(['title' => 'Visible']);

        // A draft with a showtime.
        $draft = Event::factory()->create(['title' => 'Draft']);
        $this->makeShowtimeFor($draft, $this->makeVenueFor($draft->organizer));

        // Published, but no showtime at all.
        Event::factory()->published()->create(['title' => 'No showtime']);

        // Published, but the only showtime is in the past.
        [, $past] = $this->publishedEventWithShowtime(['title' => 'Past']);
        $past->update(['starts_at' => now()->subDay()]);

        // Published, but the only showtime is cancelled.
        [, $cancelled] = $this->publishedEventWithShowtime(['title' => 'Cancelled']);
        $cancelled->update(['status' => ShowtimeStatus::Cancelled]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Visible');
    }

    public function test_the_list_shows_the_next_showtime_and_the_lowest_price(): void
    {
        [$event, $later] = $this->publishedEventWithShowtime();
        $earlier = $this->makeShowtimeFor($event, $later->venue);
        $earlier->update(['starts_at' => now()->addDays(3)]);

        $response = $this->getJson('/api/events')->assertOk()->assertJsonPath('data.0.min_price', 75000);

        $this->assertTrue(
            $earlier->fresh()->starts_at->equalTo(Carbon::parse($response->json('data.0.next_showtime_at')))
        );
    }

    public function test_the_list_does_not_leak_review_or_organizer_details(): void
    {
        $this->publishedEventWithShowtime();

        $event = $this->getJson('/api/events')->assertOk()->json('data.0');

        foreach (['rejection_reason', 'reviewed_by', 'reviewed_at', 'organizer', 'organizer_id', 'status'] as $key) {
            $this->assertArrayNotHasKey($key, $event);
        }
    }

    public function test_events_are_ordered_by_their_next_showtime(): void
    {
        $this->publishedEventWithShowtime(['title' => 'Middle'], startsAt: now()->addDays(5));
        $this->publishedEventWithShowtime(['title' => 'First'], startsAt: now()->addDays(2));
        $this->publishedEventWithShowtime(['title' => 'Last'], startsAt: now()->addDays(9));

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'First')
            ->assertJsonPath('data.1.title', 'Middle')
            ->assertJsonPath('data.2.title', 'Last');
    }

    public function test_the_list_can_be_filtered_by_category(): void
    {
        $this->publishedEventWithShowtime(['title' => 'A concert', 'category' => 'concert']);
        $this->publishedEventWithShowtime(['title' => 'A film', 'category' => 'film']);

        $this->getJson('/api/events?category=film')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'A film');

        $this->getJson('/api/events?category=wrestling')->assertUnprocessable();
    }

    public function test_the_list_can_be_searched_by_title_ignoring_case(): void
    {
        $this->publishedEventWithShowtime(['title' => 'Jakarta Symphony Night']);
        $this->publishedEventWithShowtime(['title' => 'Rock Festival']);

        $this->getJson('/api/events?search=symphony')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Jakarta Symphony Night');
    }

    public function test_percent_and_underscore_in_a_search_are_not_wildcards(): void
    {
        $this->publishedEventWithShowtime(['title' => 'Sale 100% Fun']);
        $this->publishedEventWithShowtime(['title' => 'Something else entirely']);

        $this->getJson('/api/events?'.http_build_query(['search' => '100%']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Sale 100% Fun');

        $this->getJson('/api/events?'.http_build_query(['search' => '%']))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_list_can_be_filtered_by_city(): void
    {
        $bandung = $this->makeVenueFor($this->makeOrganizer());
        $bandung->update(['city' => 'Bandung']);
        $jakarta = $this->makeVenueFor($this->makeOrganizer());
        $jakarta->update(['city' => 'Jakarta']);

        $this->publishedEventWithShowtime(['title' => 'In Bandung'], $bandung);
        $this->publishedEventWithShowtime(['title' => 'In Jakarta'], $jakarta);

        $this->getJson('/api/events?city=bandung')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'In Bandung');
    }

    public function test_the_list_is_paginated_twelve_at_a_time(): void
    {
        $venue = $this->makeVenueFor($this->makeOrganizer());

        foreach (range(1, 13) as $day) {
            $event = Event::factory()->published()->create(['organizer_id' => $venue->organizer_id]);
            Showtime::factory()->create([
                'event_id' => $event->id,
                'venue_id' => $venue->id,
                'starts_at' => now()->addDays($day),
            ]);
        }

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/events?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_event_can_be_opened_by_slug_with_its_showtimes_prices_and_availability(): void
    {
        [$event, $showtime] = $this->publishedEventWithShowtime(['slug' => 'symphony-night-abc123']);
        $showtime->seats()->orderBy('id')->first()->update(['status' => SeatStatus::Sold->value]);

        $response = $this->getJson('/api/events/symphony-night-abc123')
            ->assertOk()
            ->assertJsonPath('data.title', $event->title)
            ->assertJsonCount(1, 'data.showtimes')
            ->assertJsonPath('data.showtimes.0.venue.name', $showtime->venue->name)
            ->assertJsonPath('data.showtimes.0.availability.total', 9)
            ->assertJsonPath('data.showtimes.0.availability.available', 8)
            ->assertJsonPath('data.showtimes.0.availability.sold_out', false);

        $prices = collect($response->json('data.showtimes.0.prices'))->pluck('price', 'section_name')->all();
        $this->assertEquals(['VIP' => 150000, 'Regular' => 75000], $prices);
    }

    public function test_a_sold_out_showtime_says_so(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime(['slug' => 'sold-out-show']);
        $showtime->seats()->update(['status' => SeatStatus::Sold->value]);

        $this->getJson('/api/events/sold-out-show')
            ->assertOk()
            ->assertJsonPath('data.showtimes.0.availability.available', 0)
            ->assertJsonPath('data.showtimes.0.availability.sold_out', true);
    }

    public function test_the_detail_hides_past_and_cancelled_showtimes(): void
    {
        [$event, $upcoming] = $this->publishedEventWithShowtime(['slug' => 'multi-show']);

        $past = $this->makeShowtimeFor($event, $upcoming->venue);
        $past->update(['starts_at' => now()->subDay()]);

        $cancelled = $this->makeShowtimeFor($event, $upcoming->venue);
        $cancelled->update(['status' => ShowtimeStatus::Cancelled]);

        $this->getJson('/api/events/multi-show')
            ->assertOk()
            ->assertJsonCount(1, 'data.showtimes')
            ->assertJsonPath('data.showtimes.0.id', $upcoming->id);
    }

    public function test_unpublished_and_unknown_events_are_404(): void
    {
        Event::factory()->create(['slug' => 'secret-draft']);
        Event::factory()->pendingReview()->create(['slug' => 'in-review']);

        $this->getJson('/api/events/secret-draft')->assertNotFound();
        $this->getJson('/api/events/in-review')->assertNotFound();
        $this->getJson('/api/events/does-not-exist')->assertNotFound();
    }
}
