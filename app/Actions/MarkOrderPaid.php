<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShowtimeSeat;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Confirms a paid order: seats become sold and one ticket is issued per seat.
 *
 * Must run inside a transaction that already holds the order's row lock
 * (ProcessMidtransNotification does that).
 *
 * A payment can arrive after the order expired, for example when the buyer pays on the
 * last second. The seats are then re-claimed only if nobody else took them in the
 * meantime. If someone did, the order stays expired and the money has to be refunded
 * by hand in the Midtrans dashboard (the payment row is kept, and a warning is logged).
 */
class MarkOrderPaid
{
    /** @return bool true if the order became paid in this call */
    public function handle(Order $order): bool
    {
        if ($order->status === OrderStatus::Paid) {
            return false;
        }

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $seatIds = $items->pluck('showtime_seat_id');

        $seats = ShowtimeSeat::query()->whereIn('id', $seatIds)->orderBy('id')->lockForUpdate()->get();

        if ($order->status !== OrderStatus::Pending && ! $this->canReclaim($order, $items, $seats)) {
            Log::warning("Payment for {$order->status->value} order {$order->code} arrived but its seats are gone. Refund needed.");

            return false;
        }

        // A reclaimed order gets its items back.
        OrderItem::query()->where('order_id', $order->id)->whereNotNull('released_at')
            ->update(['released_at' => null, 'updated_at' => now()]);

        ShowtimeSeat::query()->whereIn('id', $seatIds)->update([
            'status' => SeatStatus::Sold->value,
            'held_by' => null,
            'held_until' => null,
            'order_id' => $order->id,
            'updated_at' => now(),
        ]);

        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->save();

        foreach ($items as $item) {
            Ticket::firstOrCreate(['order_item_id' => $item->id]);
        }

        return true;
    }

    private function canReclaim(Order $order, $items, $seats): bool
    {
        if ($items->isEmpty() || $seats->count() !== $items->count()) {
            return false;
        }

        $showtime = $order->showtime;

        if ($showtime->status !== ShowtimeStatus::Scheduled) {
            return false;
        }

        $untouched = $seats->every(
            fn (ShowtimeSeat $seat) => $seat->order_id === null && $seat->isTakeable()
        );

        $claimedByAnotherOrder = OrderItem::query()
            ->active()
            ->whereIn('showtime_seat_id', $seats->pluck('id'))
            ->where('order_id', '!=', $order->id)
            ->exists();

        return $untouched && ! $claimedByAnotherOrder;
    }
}
