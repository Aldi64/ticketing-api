<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShowtimeSeat;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ends a pending order that will not be paid (expired, cancelled by the user, or denied)
 * and gives its seats back. Does nothing unless the order is still pending, so it is safe
 * to call from the expiry job, the webhook and the cancel endpoint at the same time.
 */
class ReleaseOrder
{
    /** @return bool true if this call released the order */
    public function handle(Order|int $order, OrderStatus $to = OrderStatus::Expired): bool
    {
        if (! in_array($to, [OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Failed], true)) {
            throw new InvalidArgumentException('An order can only be released as expired, cancelled or failed.');
        }

        $id = $order instanceof Order ? $order->id : $order;

        return DB::transaction(function () use ($id, $to) {
            $locked = Order::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== OrderStatus::Pending) {
                return false;
            }

            $locked->status = $to;
            $locked->save();

            // Frees the partial unique index so the seats can be ordered again.
            OrderItem::query()
                ->where('order_id', $locked->id)
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            ShowtimeSeat::query()
                ->where('order_id', $locked->id)
                ->where('status', SeatStatus::Held->value)
                ->update([
                    'status' => SeatStatus::Available->value,
                    'held_by' => null,
                    'held_until' => null,
                    'order_id' => null,
                    'updated_at' => now(),
                ]);

            return true;
        });
    }
}
