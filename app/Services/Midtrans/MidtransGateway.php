<?php

namespace App\Services\Midtrans;

use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only class that talks to Midtrans. Everything else depends on this, so tests
 * can fake the HTTP calls with Http::fake() and never need real keys.
 */
class MidtransGateway
{
    /**
     * Creates a Snap transaction for the order.
     *
     * @return array{token: string, redirect_url: string}
     *
     * @throws PaymentGatewayException
     */
    public function createSnapTransaction(Order $order): array
    {
        $order->loadMissing(['user', 'items', 'showtime.event']);

        $eventTitle = $order->showtime->event->title;

        $payload = [
            'transaction_details' => [
                'order_id' => $order->midtrans_order_id,
                'gross_amount' => $order->total_amount,
            ],
            // Midtrans requires the item prices to add up to gross_amount exactly.
            'item_details' => $order->items->map(fn ($item) => [
                'id' => (string) $item->showtime_seat_id,
                'price' => $item->price,
                'quantity' => 1,
                'name' => Str::limit("{$eventTitle} {$item->seat_label}", 50, ''),
            ])->values()->all(),
            'customer_details' => [
                'first_name' => Str::limit($order->user->name, 255, ''),
                'email' => $order->user->email,
            ],
            'expiry' => [
                'unit' => 'minutes',
                'duration' => max(1, (int) ceil(now()->diffInSeconds($order->expires_at, false) / 60)),
            ],
            'callbacks' => [
                'finish' => config('ticketing.frontend_url').'/orders/'.$order->code,
            ],
        ];

        try {
            $response = Http::withBasicAuth((string) config('midtrans.server_key'), '')
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(config('midtrans.snap_url'), $payload)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            throw new PaymentGatewayException;
        }

        if (empty($response['token'])) {
            throw new PaymentGatewayException;
        }

        return [
            'token' => $response['token'],
            'redirect_url' => $response['redirect_url']
                ?? config('midtrans.redirect_base_url').'/'.$response['token'],
        ];
    }

    /** Tells Midtrans to stop accepting payment for an order. Best effort: failures are only reported. */
    public function cancel(Order $order): void
    {
        try {
            Http::withBasicAuth((string) config('midtrans.server_key'), '')
                ->acceptJson()
                ->timeout(10)
                ->post(config('midtrans.api_url')."/{$order->midtrans_order_id}/cancel");
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Asks Midtrans for the current state of an order's payment. Used when the webhook
     * has not arrived (local development without a public URL, or a missed retry).
     * The answer comes straight from Midtrans over our authenticated call, so it needs no signature.
     *
     * @return array<string, mixed>|null null when there is nothing to apply yet
     */
    public function fetchStatus(Order $order): ?array
    {
        try {
            $payload = Http::withBasicAuth((string) config('midtrans.server_key'), '')
                ->acceptJson()
                ->timeout(10)
                ->get(config('midtrans.api_url')."/{$order->midtrans_order_id}/status")
                ->json();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        // Midtrans answers 200 with status_code "404" while the buyer has not chosen a payment method.
        foreach (['order_id', 'gross_amount', 'transaction_id', 'transaction_status'] as $key) {
            if (! is_array($payload) || ! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * Midtrans signs every notification: SHA512(order_id + status_code + gross_amount + ServerKey).
     * Anyone can POST to our webhook URL, so this check is what makes it trustworthy.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hasValidSignature(array $payload): bool
    {
        $serverKey = (string) config('midtrans.server_key');

        if ($serverKey === '' || ! is_string($payload['signature_key'] ?? null)) {
            return false;
        }

        $expected = hash('sha512',
            ($payload['order_id'] ?? '').($payload['status_code'] ?? '').($payload['gross_amount'] ?? '').$serverKey
        );

        return hash_equals($expected, $payload['signature_key']);
    }
}
