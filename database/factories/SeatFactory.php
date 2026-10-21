<?php

namespace Database\Factories;

use App\Models\Seat;
use App\Models\SeatSection;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            // Keeps the section in the same venue as the seat.
            'seat_section_id' => fn (array $attributes) => SeatSection::factory()
                ->create(['venue_id' => $attributes['venue_id']])
                ->id,
            // (venue_id, row_label, seat_number) is unique. When creating many seats,
            // use a Sequence or loop to generate the grid instead of relying on random values.
            'row_label' => fake()->randomElement(range('A', 'J')),
            'seat_number' => fake()->numberBetween(1, 20),
            'is_active' => true,
        ];
    }
}
