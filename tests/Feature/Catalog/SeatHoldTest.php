<?php

namespace Tests\Feature\Catalog;

use App\Actions\HoldSeats;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class SeatHoldTest extends TestCase
{
    use CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    public function test_guests_cannot_hold_or_release_seats(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->value('id');

        $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$seatId]])->assertUnauthorized();
        $this->deleteJson("/api/showtimes/{$showtime->id}/hold")->assertUnauthorized();
    }

    public function test_a_user_can_hold_seats_and_gets_the_deadline_and_total(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $ids = $showtime->seats()->orderBy('id')->limit(2)->pluck('id')->all();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => $ids])
            ->assertOk()
            ->assertJsonCount(2, 'data.seats')
            ->assertJsonPath('data.total', 300000)
            ->assertJsonPath('data.seats.0.label', 'VIP-A1')
            ->assertJsonPath('data.seats.0.price', 150000)
            ->assertJsonStructure(['data' => ['held_until']]);

        $this->assertEqualsWithDelta(
            HoldSeats::HOLD_MINUTES * 60,
            now()->diffInSeconds($response->json('data.held_until')),
            5
        );
        $this->assertSame(2, ShowtimeSeat::where('held_by', $user->id)->where('status', SeatStatus::Held->value)->count());
    }

    public function test_the_seat_map_reflects_a_new_hold_immediately(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->orderBy('id')->value('id');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$seatId]])->assertOk();

        $this->getJson("/api/showtimes/{$showtime->id}/seats")
            ->assertJsonPath('data.seats.0.status', 'held')
            ->assertJsonPath('data.seats.0.mine', true);
    }

    public function test_a_taken_seat_returns_409_with_the_unavailable_ids_and_holds_nothing(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        [$free, $taken] = $showtime->seats()->orderBy('id')->limit(2)->pluck('id')->all();
        app(HoldSeats::class)->handle(User::factory()->create(), $showtime, [$taken]);

        $me = User::factory()->create();
        Sanctum::actingAs($me);

        $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$free, $taken]])
            ->assertStatus(409)
            ->assertJsonPath('unavailable_seat_ids', [$taken]);

        $this->assertSame(0, ShowtimeSeat::where('held_by', $me->id)->count());
    }

    public function test_seat_ids_are_validated(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $ids = $showtime->seats()->orderBy('id')->limit(7)->pluck('id')->all();
        Sanctum::actingAs(User::factory()->create());
        $url = "/api/showtimes/{$showtime->id}/hold";

        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('seat_ids');
        $this->postJson($url, ['seat_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('seat_ids');
        $this->postJson($url, ['seat_ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors('seat_ids');
        $this->postJson($url, ['seat_ids' => ['abc']])->assertUnprocessable()->assertJsonValidationErrors('seat_ids.0');
        $this->postJson($url, ['seat_ids' => [$ids[0], $ids[0]]])->assertUnprocessable();

        $this->assertSame(0, ShowtimeSeat::where('status', SeatStatus::Held->value)->count());
    }

    public function test_seats_of_another_showtime_are_rejected(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        [, $other] = $this->publishedEventWithShowtime();
        $foreignSeat = $other->seats()->value('id');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$foreignSeat]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids');
    }

    public function test_showtimes_that_are_not_bookable_cannot_be_held(): void
    {
        $draft = Event::factory()->create();
        $draftShowtime = $this->makeShowtimeFor($draft, $this->makeVenueFor($draft->organizer));

        [, $cancelled] = $this->publishedEventWithShowtime();
        $cancelled->update(['status' => ShowtimeStatus::Cancelled]);

        Sanctum::actingAs(User::factory()->create());

        foreach ([$draftShowtime, $cancelled] as $showtime) {
            $seatId = $showtime->seats()->value('id');

            $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$seatId]])->assertNotFound();
        }

        $this->assertSame(0, ShowtimeSeat::where('status', SeatStatus::Held->value)->count());
    }

    public function test_a_user_can_release_all_of_their_holds_for_a_showtime(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        [, $otherShowtime] = $this->publishedEventWithShowtime();
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        $mine = $showtime->seats()->orderBy('id')->limit(2)->pluck('id')->all();
        $theirs = $showtime->seats()->orderBy('id')->skip(2)->value('id');
        $elsewhere = $otherShowtime->seats()->orderBy('id')->value('id');

        app(HoldSeats::class)->handle($me, $showtime, $mine);
        app(HoldSeats::class)->handle($someoneElse, $showtime, [$theirs]);
        app(HoldSeats::class)->handle($me, $otherShowtime, [$elsewhere]);
        Sanctum::actingAs($me);

        $this->deleteJson("/api/showtimes/{$showtime->id}/hold")
            ->assertOk()
            ->assertJsonPath('released', 2);

        $this->assertSame(SeatStatus::Available, ShowtimeSeat::find($mine[0])->status);
        $this->assertNull(ShowtimeSeat::find($mine[0])->held_by);
        $this->assertSame(SeatStatus::Held, ShowtimeSeat::find($theirs)->status);
        $this->assertSame(SeatStatus::Held, ShowtimeSeat::find($elsewhere)->status);
    }

    public function test_a_user_can_release_only_some_of_their_seats(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        [$first, $second] = $showtime->seats()->orderBy('id')->limit(2)->pluck('id')->all();
        $me = User::factory()->create();
        app(HoldSeats::class)->handle($me, $showtime, [$first, $second]);
        Sanctum::actingAs($me);

        $this->deleteJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$first]])
            ->assertOk()
            ->assertJsonPath('released', 1);

        $this->assertSame(SeatStatus::Available, ShowtimeSeat::find($first)->status);
        $this->assertSame(SeatStatus::Held, ShowtimeSeat::find($second)->status);
    }

    public function test_someone_elses_hold_cannot_be_released(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->orderBy('id')->value('id');
        $owner = User::factory()->create();
        app(HoldSeats::class)->handle($owner, $showtime, [$seatId]);
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$seatId]])
            ->assertOk()
            ->assertJsonPath('released', 0);

        $seat = ShowtimeSeat::find($seatId);
        $this->assertSame(SeatStatus::Held, $seat->status);
        $this->assertEquals($owner->id, $seat->held_by);
    }

    public function test_seats_that_belong_to_an_order_are_not_released(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->orderBy('id')->value('id');
        $me = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $me->id, 'showtime_id' => $showtime->id]);

        app(HoldSeats::class)->handle($me, $showtime, [$seatId]);
        ShowtimeSeat::whereKey($seatId)->update(['order_id' => $order->id]);
        Sanctum::actingAs($me);

        $this->deleteJson("/api/showtimes/{$showtime->id}/hold")
            ->assertOk()
            ->assertJsonPath('released', 0);

        $this->assertSame(SeatStatus::Held, ShowtimeSeat::find($seatId)->status);
    }

    public function test_a_released_seat_can_be_held_by_someone_else(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $seatId = $showtime->seats()->orderBy('id')->value('id');
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$seatId]])->assertOk();
        $this->deleteJson("/api/showtimes/{$showtime->id}/hold")->assertOk();

        Sanctum::actingAs($second);
        $this->postJson("/api/showtimes/{$showtime->id}/hold", ['seat_ids' => [$seatId]])->assertOk();

        $this->assertEquals($second->id, ShowtimeSeat::find($seatId)->held_by);
    }
}
