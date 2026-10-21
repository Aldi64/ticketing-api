<?php

namespace Tests\Feature\Seats;

use App\Actions\HoldSeats;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesShowtimeWithSeats;
use Tests\TestCase;

class HoldSeatsTest extends TestCase
{
    use CreatesShowtimeWithSeats, RefreshDatabase;

    /** @return array<int, int> */
    private function seatIds(Showtime $showtime, int $count = 2): array
    {
        return $showtime->seats()->orderBy('id')->limit($count)->pluck('id')->all();
    }

    public function test_it_holds_available_seats_for_the_user(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $ids = $this->seatIds($showtime);

        $held = app(HoldSeats::class)->handle($user, $showtime, $ids);

        $this->assertCount(2, $held);

        foreach ($held as $seat) {
            $this->assertSame(SeatStatus::Held, $seat->status);
            $this->assertEquals($user->id, $seat->held_by);
            $this->assertTrue($seat->held_until->equalTo(now()->addMinutes(HoldSeats::HOLD_MINUTES)));
        }
    }

    public function test_a_second_user_cannot_hold_seats_that_are_already_held(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $showtime = $this->makeShowtime();
        $ids = $this->seatIds($showtime);

        app(HoldSeats::class)->handle($first, $showtime, $ids);

        try {
            app(HoldSeats::class)->handle($second, $showtime, $ids);
            $this->fail('Expected SeatsUnavailableException.');
        } catch (SeatsUnavailableException $e) {
            $this->assertEqualsCanonicalizing($ids, $e->seatIds);
        }

        $this->assertSame(2, ShowtimeSeat::query()->where('held_by', $first->id)->count());
    }

    public function test_holding_is_all_or_nothing(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $showtime = $this->makeShowtime();
        [$freeSeat, $takenSeat] = $this->seatIds($showtime);

        app(HoldSeats::class)->handle($first, $showtime, [$takenSeat]);

        try {
            app(HoldSeats::class)->handle($second, $showtime, [$freeSeat, $takenSeat]);
            $this->fail('Expected SeatsUnavailableException.');
        } catch (SeatsUnavailableException $e) {
            $this->assertEquals([$takenSeat], $e->seatIds);
        }

        $this->assertSame(SeatStatus::Available, ShowtimeSeat::find($freeSeat)->status);
    }

    public function test_an_expired_hold_can_be_taken_by_someone_else(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $showtime = $this->makeShowtime();
        $ids = $this->seatIds($showtime);

        app(HoldSeats::class)->handle($first, $showtime, $ids);
        ShowtimeSeat::query()->whereIn('id', $ids)->update(['held_until' => now()->subMinute()]);

        $held = app(HoldSeats::class)->handle($second, $showtime, $ids);

        foreach ($held as $seat) {
            $this->assertEquals($second->id, $seat->held_by);
        }
    }

    public function test_the_same_user_can_hold_again_to_extend_the_hold(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $ids = $this->seatIds($showtime);

        app(HoldSeats::class)->handle($user, $showtime, $ids);
        $this->travel(5)->minutes();
        $held = app(HoldSeats::class)->handle($user, $showtime, $ids);

        foreach ($held as $seat) {
            $this->assertTrue($seat->held_until->equalTo(now()->addMinutes(HoldSeats::HOLD_MINUTES)));
        }
    }

    public function test_sold_seats_cannot_be_held(): void
    {
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $ids = $this->seatIds($showtime, 1);
        ShowtimeSeat::query()->whereIn('id', $ids)->update(['status' => SeatStatus::Sold->value]);

        $this->expectException(SeatsUnavailableException::class);

        app(HoldSeats::class)->handle($user, $showtime, $ids);
    }

    public function test_at_most_six_seats_per_request(): void
    {
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();

        $this->expectException(ValidationException::class);

        app(HoldSeats::class)->handle($user, $showtime, $this->seatIds($showtime, HoldSeats::MAX_SEATS + 1));
    }

    public function test_at_least_one_seat_is_required(): void
    {
        $this->expectException(ValidationException::class);

        app(HoldSeats::class)->handle(User::factory()->create(), $this->makeShowtime(), []);
    }

    public function test_seats_from_another_showtime_are_rejected(): void
    {
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $otherShowtime = $this->makeShowtime();

        $this->expectException(ValidationException::class);

        app(HoldSeats::class)->handle($user, $showtime, $this->seatIds($otherShowtime, 1));
    }

    public function test_cancelled_showtimes_cannot_be_booked(): void
    {
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $showtime->update(['status' => ShowtimeStatus::Cancelled]);

        $this->expectException(ValidationException::class);

        app(HoldSeats::class)->handle($user, $showtime, $this->seatIds($showtime, 1));
    }

    public function test_past_showtimes_cannot_be_booked(): void
    {
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $showtime->update(['starts_at' => now()->subHour()]);

        $this->expectException(ValidationException::class);

        app(HoldSeats::class)->handle($user, $showtime, $this->seatIds($showtime, 1));
    }
}
