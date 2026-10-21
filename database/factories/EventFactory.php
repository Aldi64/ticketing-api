<?php

namespace Database\Factories;

use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'organizer_id' => User::factory()->organizer(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'category' => fake()->randomElement(EventCategory::cases()),
            'description' => fake()->paragraph(),
            'poster_path' => null,
            'status' => EventStatus::Draft,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => ['status' => EventStatus::PendingReview]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Published,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Does not meet our guidelines.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }
}
