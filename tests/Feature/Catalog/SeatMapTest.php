<?php

namespace Tests\Feature\Catalog;

use App\Actions\HoldSeats;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Models\Event;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class SeatMapTest extends TestCase
{
    use CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    public function test_a_guest_can_see_the_seat_map_in_row_and_seat_order(): void
    {
        [$event, $showtime] = $this->publishedEventWithShowtime();

        $response = $this->getJson("/api/showtimes/{$showtime->id}/seats")
            ->assertOk()
            ->assertJsonPath('data.showtime.id', $showtime->id)
            ->assertJsonPath('data.showtime.event.title', $event->title)
            ->assertJsonCount(9, 'data.seats')
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.seats.0.row_label', 'A')
            ->assertJsonPath('data.seats.0.seat_number', 1)
            ->assertJsonPath('data.seats.8.row_label', 'C')
            ->assertJsonPath('data.seats.8.seat_number', 3)
            ->assertJsonPath('data.seats.0.status', 'available')
            ->assertJsonPath('data.seats.0.price', 150000)
            ->assertJsonPath('data.seats.8.price', 75000)
            ->assertJsonStructure(['meta' => ['server_time']]);

        $sections = collect($response->json('data.sections'))->pluck('price', 'name')->all();
        $this->assertEquals(['VIP' => 150000, 'Regular' => 75000], $sections);
    }

    public function test_sold_held_and_available_seats_are_reported(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $ids = $showtime->seats()->orderBy('id')->pluck('id');

        ShowtimeSeat::whereKey($ids[0])->update(['status' => SeatStatus::Sold->value]);
        app(HoldSeats::class)->handle(User::factory()->create(), $showtime, [$ids[1]]);

        $this->getJson("/api/showtimes/{$showtime->id}/seats")
            ->assertOk()
            ->assertJsonPath('data.seats.0.status', 'sold')
            ->assertJsonPath('data.seats.1.status', 'held')
            ->assertJsonPath('data.seats.2.status', 'available');
    }

    public function test_an_expired_hold_shows_as_available(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->orderBy('id')->value('id');

        app(HoldSeats::class)->handle(User::factory()->create(), $showtime, [$seatId]);
        ShowtimeSeat::whereKey($seatId)->update(['held_until' => now()->subMinute()]);

        $this->getJson("/api/showtimes/{$showtime->id}/seats")
            ->assertOk()
            ->assertJsonPath('data.seats.0.status', 'available');
    }

    public function test_my_own_holds_are_marked_and_show_a_deadline(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $ids = $showtime->seats()->orderBy('id')->pluck('id');
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        app(HoldSeats::class)->handle($me, $showtime, [$ids[0], $ids[1]]);
        app(HoldSeats::class)->handle($someoneElse, $showtime, [$ids[2]]);
        Sanctum::actingAs($me);

        $this->getJson("/api/showtimes/{$showtime->id}/seats")
            ->assertOk()
            ->assertJsonPath('data.seats.0.mine', true)
            ->assertJsonPath('data.seats.1.mine', true)
            ->assertJsonPath('data.seats.2.mine', false)
            ->assertJsonPath('data.seats.2.status', 'held')
            ->assertJsonPath('data.seats.2.held_until', null)
            ->assertJsonPath('data.seats.3.mine', false)
            ->assertJsonMissingPath('data.seats.2.held_by')
            ->assertJsonMissingPath('data.seats.0.held_by');

        $this->assertNotNull($this->getJson("/api/showtimes/{$showtime->id}/seats")->json('data.seats.0.held_until'));
    }

    public function test_a_guest_never_sees_holds_as_theirs(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->orderBy('id')->value('id');
        app(HoldSeats::class)->handle(User::factory()->create(), $showtime, [$seatId]);

        $this->getJson("/api/showtimes/{$showtime->id}/seats")
            ->assertOk()
            ->assertJsonPath('data.seats.0.status', 'held')
            ->assertJsonPath('data.seats.0.mine', false)
            ->assertJsonPath('data.seats.0.held_until', null);
    }

    public function test_showtimes_the_public_may_not_see_are_404(): void
    {
        $draft = Event::factory()->create();
        $draftShowtime = $this->makeShowtimeFor($draft, $this->makeVenueFor($draft->organizer));

        [, $cancelled] = $this->publishedEventWithShowtime();
        $cancelled->update(['status' => ShowtimeStatus::Cancelled]);

        [, $started] = $this->publishedEventWithShowtime();
        $started->update(['starts_at' => now()->subHour()]);

        $this->getJson("/api/showtimes/{$draftShowtime->id}/seats")->assertNotFound();
        $this->getJson("/api/showtimes/{$cancelled->id}/seats")->assertNotFound();
        $this->getJson("/api/showtimes/{$started->id}/seats")->assertNotFound();
        $this->getJson('/api/showtimes/999999/seats')->assertNotFound();
        $this->getJson('/api/showtimes/not-a-number/seats')->assertNotFound();
    }
}
