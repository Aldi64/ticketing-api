<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\OrderPaid;
use Illuminate\Support\Facades\DB;

/**
 * Handles one Midtrans HTTP notification whose signature was already verified.
 *
 * Idempotent: Midtrans retries notifications, so the same (transaction_id, status) pair
 * is only ever applied once. The order row is locked first, which also serializes two
 * notifications that arrive at the same moment.
 */
class ProcessMidtransNotification
{
    public const PROCESSED = 'processed';

    public const DUPLICATE = 'duplicate';

    public const UNKNOWN_ORDER = 'unknown_order';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public function __construct(
        private readonly MarkOrderPaid $markPaid,
        private readonly ReleaseOrder $release,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload): string
    {
        $order = Order::query()->where('midtrans_order_id', $payload['order_id'])->first();

        if ($order === null) {
            return self::UNKNOWN_ORDER;
        }

        // Never trust an amount that differs from what we charged.
        if ((int) round((float) $payload['gross_amount']) !== $order->total_amount) {
            return self::AMOUNT_MISMATCH;
        }

        $newlyPaid = false;

        $result = DB::transaction(function () use ($order, $payload, &$newlyPaid) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $seen = Payment::query()
                ->where('transaction_id', $payload['transaction_id'])
                ->where('transaction_status', $payload['transaction_status'])
                ->exists();

            if ($seen) {
                return self::DUPLICATE;
            }

            Payment::create([
                'order_id' => $locked->id,
                'transaction_id' => $payload['transaction_id'],
                'transaction_status' => $payload['transaction_status'],
                'payment_type' => $payload['payment_type'] ?? null,
                'fraud_status' => $payload['fraud_status'] ?? null,
                'gross_amount' => (int) round((float) $payload['gross_amount']),
                'signature_valid' => true,
                'raw_payload' => $payload,
            ]);

            $newlyPaid = $this->apply($locked, $payload);

            return self::PROCESSED;
        });

        if ($newlyPaid) {
            $this->notifyBuyer($order->id);
        }

        return $result;
    }

    /** @param  array<string, mixed>  $payload  @return bool true if the order became paid */
    private function apply(Order $order, array $payload): bool
    {
        $status = $payload['transaction_status'];
        $fraud = $payload['fraud_status'] ?? null;

        // "capture" is a card payment: only "accept" is safe, "challenge" waits for review.
        $isPaid = $status === 'settlement' || ($status === 'capture' && $fraud === 'accept');

        if ($isPaid) {
            return $this->markPaid->handle($order);
        }

        match ($status) {
            'deny', 'failure' => $this->release->handle($order, OrderStatus::Failed),
            'cancel' => $this->release->handle($order, OrderStatus::Cancelled),
            'expire' => $this->release->handle($order, OrderStatus::Expired),
            default => null, // pending, challenge, ...
        };

        return false;
    }

    private function notifyBuyer(int $orderId): void
    {
        $order = Order::query()->with(['user', 'items', 'showtime.event'])->find($orderId);

        $order?->user->notify(new OrderPaid(
            orderId: $order->id,
            code: $order->code,
            eventTitle: $order->showtime->event->title,
            startsAt: $order->showtime->starts_at->toDayDateTimeString(),
            seats: $order->items->pluck('seat_label')->all(),
            total: $order->total_amount,
        ));
    }
}
