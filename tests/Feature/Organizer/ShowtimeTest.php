<?php

namespace Tests\Feature\Organizer;

use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\TestCase;

class ShowtimeTest extends TestCase
{
    use CreatesOrganizerFixtures, RefreshDatabase;

    public function test_creating_a_showtime_generates_priced_seats(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$event->id}/showtimes", [
            'venue_id' => $venue->id,
            'starts_at' => now()->addWeeks(2)->toIso8601String(),
            'prices' => $this->pricesFor($venue),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.seat_counts.total', 9)
            ->assertJsonPath('data.seat_counts.available', 9)
            ->assertJsonCount(2, 'data.prices');

        $showtime = Showtime::firstOrFail();
        $this->assertSame(9, ShowtimeSeat::where('showtime_id', $showtime->id)->count());
        $this->assertSame(3, ShowtimeSeat::where('price', 150000)->count());
        $this->assertSame(6, ShowtimeSeat::where('price', 75000)->count());
    }

    public function test_a_venue_of_another_organizer_is_rejected(): void
    {
        $organizer = $this->makeOrganizer();
        $foreignVenue = $this->makeVenueFor($this->makeOrganizer());
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$event->id}/showtimes", [
            'venue_id' => $foreignVenue->id,
            'starts_at' => now()->addWeek()->toIso8601String(),
            'prices' => $this->pricesFor($foreignVenue),
        ])->assertUnprocessable()->assertJsonValidationErrors('venue_id');

        $this->assertSame(0, Showtime::count());
    }

    public function test_a_missing_section_price_rolls_everything_back(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$event->id}/showtimes", [
            'venue_id' => $venue->id,
            'starts_at' => now()->addWeek()->toIso8601String(),
            'prices' => [$this->pricesFor($venue)[0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('prices');

        $this->assertSame(0, Showtime::count());
        $this->assertSame(0, ShowtimeSeat::count());
    }

    public function test_the_start_time_must_be_in_the_future(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$event->id}/showtimes", [
            'venue_id' => $venue->id,
            'starts_at' => now()->subHour()->toIso8601String(),
            'prices' => $this->pricesFor($venue),
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_prices_must_be_positive(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $prices = $this->pricesFor($venue);
        $prices[0]['price'] = 0;

        $this->postJson("/api/organizer/events/{$event->id}/showtimes", [
            'venue_id' => $venue->id,
            'starts_at' => now()->addWeek()->toIso8601String(),
            'prices' => $prices,
        ])->assertUnprocessable()->assertJsonValidationErrors('prices.0.price');
    }

    public function test_only_the_event_owner_can_add_showtimes(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $othersEvent = Event::factory()->create();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$othersEvent->id}/showtimes", [
            'venue_id' => $venue->id,
            'starts_at' => now()->addWeek()->toIso8601String(),
            'prices' => $this->pricesFor($venue),
        ])->assertForbidden();

        $this->assertSame(0, Showtime::count());
    }

    public function test_the_index_reports_seat_counts_by_status(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $showtime = $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        $seats = $showtime->seats()->orderBy('id')->limit(3)->pluck('id');
        ShowtimeSeat::whereKey($seats[0])->update(['status' => SeatStatus::Sold->value]);
        ShowtimeSeat::whereKey($seats[1])->update(['status' => SeatStatus::Held->value]);
        Sanctum::actingAs($organizer);

        $this->getJson("/api/organizer/events/{$event->id}/showtimes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seat_counts.total', 9)
            ->assertJsonPath('data.0.seat_counts.available', 7)
            ->assertJsonPath('data.0.seat_counts.held', 1)
            ->assertJsonPath('data.0.seat_counts.sold', 1);
    }

    public function test_a_showtime_can_be_cancelled(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $showtime = $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/showtimes/{$showtime->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(ShowtimeStatus::Cancelled, $showtime->fresh()->status);

        $this->postJson("/api/organizer/showtimes/{$showtime->id}/cancel")->assertStatus(409);
    }

    public function test_a_showtime_with_paid_orders_cannot_be_cancelled(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $showtime = $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        Order::factory()->paid()->create(['showtime_id' => $showtime->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/showtimes/{$showtime->id}/cancel")->assertStatus(409);

        $this->assertSame(ShowtimeStatus::Scheduled, $showtime->fresh()->status);
    }

    public function test_only_the_event_owner_can_cancel_a_showtime(): void
    {
        $owner = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $owner->id]);
        $showtime = $this->makeShowtimeFor($event, $this->makeVenueFor($owner));
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson("/api/organizer/showtimes/{$showtime->id}/cancel")->assertForbidden();

        $this->assertSame(ShowtimeStatus::Scheduled, $showtime->fresh()->status);
    }
}
