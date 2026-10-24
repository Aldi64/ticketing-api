<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Showtime;

/**
 * Expires pending orders whose payment window has passed. Runs every minute from the
 * scheduler, and also right before seats are held, so a seat whose buyer never paid
 * is free the moment its time is up instead of up to a minute later.
 */
class ExpireStaleOrders
{
    public function __construct(private readonly ReleaseOrder $release) {}

    /** @return int number of orders expired */
    public function handle(?Showtime $showtime = null): int
    {
        $expired = 0;

        Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->when($showtime, fn ($query) => $query->where('showtime_id', $showtime->id))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$expired) {
                if ($this->release->handle($id, OrderStatus::Expired)) {
                    $expired++;
                }
            });

        return $expired;
    }
}
