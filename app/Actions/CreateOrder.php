<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\User;
use App\Services\Midtrans\MidtransGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns the seats a user is holding into a pending order and asks Midtrans for a Snap token.
 *
 * Two steps on purpose. The order and the seat bookkeeping are committed first, so no
 * database locks are held while we wait for an HTTP call. If Midtrans then fails, the
 * order is released again and the seats go back on sale.
 */
class CreateOrder
{
    /** How long the buyer has to pay once the order exists. */
    public const ORDER_MINUTES = 15;

    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly ReleaseOrder $release,
    ) {}

    /**
     * @throws ValidationException when the user holds no seats for this showtime
     * @throws PaymentGatewayException when Midtrans fails
     */
    public function handle(User $user, Showtime $showtime): Order
    {
        $order = $this->createPendingOrder($user, $showtime);

        try {
            $snap = $this->gateway->createSnapTransaction($order);
        } catch (PaymentGatewayException $e) {
            $this->release->handle($order, OrderStatus::Failed);

            throw $e;
        }

        $order->update(['snap_token' => $snap['token']]);

        return $order->load(['items', 'showtime.event', 'showtime.venue']);
    }

    private function createPendingOrder(User $user, Showtime $showtime): Order
    {
        return DB::transaction(function () use ($user, $showtime) {
            $seats = ShowtimeSeat::query()
                ->where('showtime_id', $showtime->id)
                ->where('status', SeatStatus::Held->value)
                ->where('held_by', $user->id)
                ->whereNull('order_id')
                ->where('held_until', '>', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($seats->isEmpty()) {
                throw ValidationException::withMessages([
                    'seats' => 'You have no seats on hold for this showtime. Pick your seats again.',
                ]);
            }

            $seats->load('seat.section');

            $order = new Order([
                'user_id' => $user->id,
                'showtime_id' => $showtime->id,
                'total_amount' => $seats->sum('price'),
                'expires_at' => now()->addMinutes(self::ORDER_MINUTES),
            ]);
            $order->code = Str::upper(Str::random(10));
            $order->midtrans_order_id = 'TKT-'.$order->code;
            $order->save();

            foreach ($seats as $seat) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'showtime_seat_id' => $seat->id,
                    'seat_label' => $seat->seat->label(),
                    'price' => $seat->price,
                ]);
            }

            // The seats now belong to the order and stay held until it expires, so nobody
            // else can take them while the buyer is paying.
            ShowtimeSeat::query()->whereIn('id', $seats->pluck('id'))->update([
                'order_id' => $order->id,
                'held_until' => $order->expires_at,
                'updated_at' => now(),
            ]);

            return $order;
        });
    }
}
