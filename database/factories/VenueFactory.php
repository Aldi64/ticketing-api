<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organizer_id' => User::factory()->organizer(),
            'name' => fake()->company().' Hall',
            'city' => fake()->city(),
            'address' => fake()->streetAddress(),
        ];
    }
}
