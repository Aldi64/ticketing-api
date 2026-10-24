<?php

namespace Tests\Concerns;

use App\Actions\CreateOrder;
use App\Actions\HoldSeats;
use App\Models\Order;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Helpers for the order and payment tests. Use together with CreatesPublishedEvents. */
trait CreatesOrders
{
    /** Pretends to be Midtrans and sets test keys, so no real call or real key is ever needed. */
    protected function fakeMidtrans(): void
    {
        config([
            'midtrans.server_key' => 'test-server-key',
            'midtrans.client_key' => 'test-client-key',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*midtrans.com/*' => Http::response([
                'token' => 'snap-token-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-123',
            ], 201),
        ]);
    }

    /**
     * Holds the first $count seats (after skipping $skip) of the showtime for the user.
     *
     * @return array<int, int> showtime_seats ids
     */
    protected function holdSeats(User $user, Showtime $showtime, int $count = 2, int $skip = 0): array
    {
        $ids = $showtime->seats()->orderBy('id')->skip($skip)->limit($count)->pluck('id')->all();

        app(HoldSeats::class)->handle($user, $showtime, $ids);

        return $ids;
    }

    /** Holds seats and turns them into a pending order, like the checkout does. */
    protected function placeOrder(User $user, Showtime $showtime, int $count = 2, int $skip = 0): Order
    {
        $this->holdSeats($user, $showtime, $count, $skip);

        return app(CreateOrder::class)->handle($user, $showtime);
    }

    /**
     * A signed Midtrans notification for the order, as the webhook receives it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function notificationFor(Order $order, string $status = 'settlement', array $overrides = []): array
    {
        $payload = array_merge([
            'order_id' => $order->midtrans_order_id,
            'status_code' => '200',
            'gross_amount' => number_format($order->total_amount, 2, '.', ''),
            'transaction_id' => "txn-{$status}-{$order->id}",
            'transaction_status' => $status,
            'payment_type' => 'bank_transfer',
            'fraud_status' => 'accept',
        ], $overrides);

        $payload['signature_key'] ??= hash('sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('midtrans.server_key')
        );

        return $payload;
    }
}
