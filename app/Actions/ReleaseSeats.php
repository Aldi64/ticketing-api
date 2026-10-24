<?php

namespace App\Actions;

use App\Enums\SeatStatus;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\User;

/**
 * Lets a user give back seats they are holding, for example when they deselect a seat or
 * leave the checkout page. Only the user's own holds are touched, and seats that already
 * belong to an order are left alone: those are handled by the order flow.
 */
class ReleaseSeats
{
    /**
     * @param  array<int, int>|null  $showtimeSeatIds  limit the release to these seats, or null for all of the user's holds
     * @return int number of seats released
     */
    public function handle(User $user, Showtime $showtime, ?array $showtimeSeatIds = null): int
    {
        return ShowtimeSeat::query()
            ->where('showtime_id', $showtime->id)
            ->where('status', SeatStatus::Held->value)
            ->where('held_by', $user->id)
            ->whereNull('order_id')
            ->when($showtimeSeatIds, fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->update([
                'status' => SeatStatus::Available->value,
                'held_by' => null,
                'held_until' => null,
                'updated_at' => now(),
            ]);
    }
}
