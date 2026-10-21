<?php

namespace Tests\Feature\Seats;

use App\Actions\CreateShowtimeSeats;
use App\Enums\SeatStatus;
use App\Models\Seat;
use App\Models\SeatSection;
use App\Models\ShowtimeSeat;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesShowtimeWithSeats;
use Tests\TestCase;

class CreateShowtimeSeatsTest extends TestCase
{
    use CreatesShowtimeWithSeats, RefreshDatabase;

    public function test_it_creates_one_available_row_per_active_seat(): void
    {
        $showtime = $this->makeShowtime();

        $this->assertSame(10, $showtime->seats()->count());
        $this->assertSame(0, $showtime->seats()->where('status', '!=', SeatStatus::Available->value)->count());
    }

    public function test_each_seat_gets_the_price_of_its_section(): void
    {
        $showtime = $this->makeShowtime();

        $vipPrices = ShowtimeSeat::query()
            ->where('showtime_id', $showtime->id)
            ->whereHas('seat.section', fn ($q) => $q->where('name', 'VIP'))
            ->pluck('price')->unique()->values()->all();

        $regularPrices = ShowtimeSeat::query()
            ->where('showtime_id', $showtime->id)
            ->whereHas('seat.section', fn ($q) => $q->where('name', 'Regular'))
            ->pluck('price')->unique()->values()->all();

        $this->assertEquals([150000], $vipPrices);
        $this->assertEquals([75000], $regularPrices);
        $this->assertSame(2, $showtime->sectionPrices()->count());
    }

    public function test_inactive_seats_are_skipped(): void
    {
        $showtime = $this->makeShowtime(generateSeats: false);
        Seat::query()->where('venue_id', $showtime->venue_id)->first()->update(['is_active' => false]);

        $created = app(CreateShowtimeSeats::class)->handle($showtime, $this->sectionPrices);

        $this->assertSame(9, $created);
        $this->assertSame(9, $showtime->seats()->count());
    }

    public function test_every_section_with_seats_needs_a_price(): void
    {
        $showtime = $this->makeShowtime(generateSeats: false);
        $onlyVip = [array_key_first($this->sectionPrices) => 100000];

        $this->expectException(ValidationException::class);

        try {
            app(CreateShowtimeSeats::class)->handle($showtime, $onlyVip);
        } finally {
            $this->assertSame(0, $showtime->seats()->count());
        }
    }

    public function test_prices_for_sections_of_another_venue_are_rejected(): void
    {
        $showtime = $this->makeShowtime(generateSeats: false);
        $foreignSection = SeatSection::factory()->create([
            'venue_id' => Venue::factory()->create()->id,
            'name' => 'VIP',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateShowtimeSeats::class)->handle($showtime, $this->sectionPrices + [$foreignSection->id => 100000]);
    }

    public function test_prices_must_be_positive_whole_numbers(): void
    {
        $showtime = $this->makeShowtime(generateSeats: false);
        $prices = array_map(fn () => 0, $this->sectionPrices);

        $this->expectException(ValidationException::class);

        app(CreateShowtimeSeats::class)->handle($showtime, $prices);
    }

    public function test_seats_cannot_be_generated_twice(): void
    {
        $showtime = $this->makeShowtime();

        $this->expectException(LogicException::class);

        app(CreateShowtimeSeats::class)->handle($showtime, $this->sectionPrices);
    }
}
