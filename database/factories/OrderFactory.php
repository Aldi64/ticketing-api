<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'showtime_id' => Showtime::factory(),
            'total_amount' => fake()->numberBetween(5, 50) * 10000,
            'status' => OrderStatus::Pending,
            'midtrans_order_id' => 'ORD-'.Str::upper(Str::random(12)),
            'snap_token' => null,
            'expires_at' => now()->addMinutes(15),
            'paid_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
