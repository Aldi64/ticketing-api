<?php

namespace Database\Factories;

use App\Enums\ShowtimeStatus;
use App\Models\Event;
use App\Models\Showtime;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Showtime>
 */
class ShowtimeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'venue_id' => Venue::factory(),
            'starts_at' => fake()->dateTimeBetween('+1 week', '+2 months'),
            'status' => ShowtimeStatus::Scheduled,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ShowtimeStatus::Cancelled]);
    }
}
