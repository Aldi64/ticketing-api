<?php

namespace Database\Factories;

use App\Enums\SeatStatus;
use App\Models\Seat;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShowtimeSeat>
 */
class ShowtimeSeatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'showtime_id' => Showtime::factory(),
            'seat_id' => Seat::factory(),
            'price' => fake()->numberBetween(5, 50) * 10000,
            'status' => SeatStatus::Available,
            'held_by' => null,
            'held_until' => null,
            'order_id' => null,
        ];
    }

    /** A seat held by a user. Use a negative $minutes value to simulate an expired hold. */
    public function held(?User $user = null, int $minutes = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeatStatus::Held,
            'held_by' => $user?->id ?? User::factory(),
            'held_until' => now()->addMinutes($minutes),
        ]);
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeatStatus::Sold,
            'held_by' => null,
            'held_until' => null,
        ]);
    }
}
