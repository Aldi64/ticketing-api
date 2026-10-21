<?php

namespace App\Console\Commands;

use App\Enums\SeatStatus;
use App\Models\ShowtimeSeat;
use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'seats:release-expired';

    protected $description = 'Release seat holds whose hold time has expired and that have no order';

    public function handle(): int
    {
        // Held seats that belong to an order are left for the order-expiry flow.
        // Seats are also treated as free by ShowtimeSeat::takeable() once their hold
        // expires, so this cleanup only keeps the seat map and stored status tidy.
        $released = ShowtimeSeat::query()
            ->where('status', SeatStatus::Held->value)
            ->where('held_until', '<', now())
            ->whereNull('order_id')
            ->update([
                'status' => SeatStatus::Available->value,
                'held_by' => null,
                'held_until' => null,
                'updated_at' => now(),
            ]);

        $this->info("Released {$released} expired seat hold(s).");

        return self::SUCCESS;
    }
}
