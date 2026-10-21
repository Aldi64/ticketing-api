<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShowtimeSeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'showtime_seat_id' => ShowtimeSeat::factory(),
            'seat_label' => fake()->randomElement(['VIP', 'Regular']).'-'
                .fake()->randomElement(range('A', 'J')).fake()->numberBetween(1, 20),
            'price' => fake()->numberBetween(5, 50) * 10000,
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => ['released_at' => now()]);
    }
}
