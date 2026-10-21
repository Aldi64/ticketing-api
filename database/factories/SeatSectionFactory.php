<?php

namespace Database\Factories;

use App\Models\SeatSection;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatSection>
 */
class SeatSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            // (venue_id, name) is unique. In tests that create several sections per venue,
            // pass explicit names such as 'VIP' and 'Regular'.
            'name' => fake()->unique()->word(),
        ];
    }
}
