<?php

namespace Tests\Feature\Orders;

use App\Actions\ReleaseOrder;
use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ShowtimeSeat;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\OrderPaid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrders;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class MidtransWebhookTest extends TestCase
{
    use CreatesOrders, CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    private const URL = '/api/payments/midtrans/notification';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMidtrans();
    }

    /** @return array{0: User, 1: \App\Models\Showtime, 2: Order} */
    private function pendingOrder(int $count = 2): array
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();

        return [$user, $showtime, $this->placeOrder($user, $showtime, $count)];
    }

    public function test_a_wrong_signature_is_rejected_and_changes_nothing(): void
    {
        [, , $order] = $this->pendingOrder();

        $payload = $this->notificationFor($order, 'settlement', ['signature_key' => str_repeat('a', 128)]);

        $this->postJson(self::URL, $payload)->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_tampered_notification_is_rejected(): void
    {
        [, , $order] = $this->pendingOrder();

        $payload = $this->notificationFor($order);
        $payload['gross_amount'] = '1000.00'; // signed for another amount

        $this->postJson(self::URL, $payload)->assertForbidden();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_malformed_notifications_are_rejected(): void
    {
        $this->postJson(self::URL, [])->assertStatus(400);
        $this->postJson(self::URL, ['order_id' => 'x'])->assertStatus(400);
    }

    public function test_a_settlement_pays_the_order_sells_the_seats_and_issues_tickets(): void
    {
        Notification::fake();
        [$user, , $order] = $this->pendingOrder(2);

        $this->postJson(self::URL, $this->notificationFor($order))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(2, ShowtimeSeat::where('order_id', $order->id)->where('status', SeatStatus::Sold->value)->count());
        $this->assertSame(0, ShowtimeSeat::where('status', SeatStatus::Held->value)->count());

        $tickets = Ticket::query()->whereIn('order_item_id', $order->items()->pluck('id'))->get();
        $this->assertCount(2, $tickets);
        $this->assertCount(2, $tickets->pluck('code')->unique());

        $payment = Payment::firstOrFail();
        $this->assertSame($order->id, $payment->order_id);
        $this->assertTrue($payment->signature_valid);
        $this->assertSame(300000, $payment->gross_amount);
        $this->assertSame('bank_transfer', $payment->payment_type);

        Notification::assertSentTo($user, OrderPaid::class);
    }

    public function test_the_buyer_sees_their_tickets_after_paying(): void
    {
        Notification::fake();
        [$user, , $order] = $this->pendingOrder(2);
        $this->postJson(self::URL, $this->notificationFor($order))->assertOk();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/orders/{$order->code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonMissingPath('data.snap_token');

        foreach ($response->json('data.items') as $item) {
            $this->assertNotEmpty($item['ticket']['code']);
            $this->assertNull($item['ticket']['checked_in_at']);
        }
    }

    public function test_a_repeated_notification_is_applied_only_once(): void
    {
        Notification::fake();
        [$user, , $order] = $this->pendingOrder(2);
        $payload = $this->notificationFor($order);

        $this->postJson(self::URL, $payload)->assertOk();
        $this->postJson(self::URL, $payload)->assertOk();
        $this->postJson(self::URL, $payload)->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(2, Ticket::count());
        Notification::assertSentToTimes($user, OrderPaid::class, 1);
    }

    public function test_a_card_capture_is_paid_only_when_the_fraud_check_accepts_it(): void
    {
        Notification::fake();
        [, , $challenged] = $this->pendingOrder(1);
        [, , $accepted] = $this->pendingOrder(1);

        $this->postJson(self::URL, $this->notificationFor($challenged, 'capture', ['fraud_status' => 'challenge']))->assertOk();
        $this->postJson(self::URL, $this->notificationFor($accepted, 'capture', ['fraud_status' => 'accept']))->assertOk();

        $this->assertSame(OrderStatus::Pending, $challenged->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $accepted->fresh()->status);
    }

    public function test_a_pending_notification_is_stored_but_changes_nothing(): void
    {
        [, , $order] = $this->pendingOrder();

        $this->postJson(self::URL, $this->notificationFor($order, 'pending'))->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(1, Payment::where('transaction_status', 'pending')->count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_expire_cancel_and_deny_release_the_order_and_its_seats(): void
    {
        $expected = ['expire' => OrderStatus::Expired, 'cancel' => OrderStatus::Cancelled, 'deny' => OrderStatus::Failed];

        foreach ($expected as $midtransStatus => $orderStatus) {
            [, $showtime, $order] = $this->pendingOrder(2);

            $this->postJson(self::URL, $this->notificationFor($order, $midtransStatus))->assertOk();

            $this->assertSame($orderStatus, $order->fresh()->status, $midtransStatus);
            $this->assertSame(0, OrderItem::where('order_id', $order->id)->active()->count());
            $this->assertSame(0, ShowtimeSeat::where('showtime_id', $showtime->id)->where('status', SeatStatus::Held->value)->count());
            $this->assertSame(0, Ticket::count());
        }
    }

    public function test_a_later_expire_notification_does_not_undo_a_paid_order(): void
    {
        Notification::fake();
        [, , $order] = $this->pendingOrder(2);

        $this->postJson(self::URL, $this->notificationFor($order, 'settlement'))->assertOk();
        $this->postJson(self::URL, $this->notificationFor($order, 'expire'))->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(2, ShowtimeSeat::where('status', SeatStatus::Sold->value)->count());
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->active()->count());
    }

    public function test_the_amount_must_match_the_order(): void
    {
        [, , $order] = $this->pendingOrder();

        $payload = $this->notificationFor($order, 'settlement', ['gross_amount' => '1000.00']);

        $this->postJson(self::URL, $payload)->assertStatus(400);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_an_unknown_order_is_a_404(): void
    {
        [, , $order] = $this->pendingOrder();

        $payload = $this->notificationFor($order, 'settlement', ['order_id' => 'TKT-NOPE']);

        $this->postJson(self::URL, $payload)->assertNotFound();
    }

    public function test_a_late_payment_still_wins_the_seats_if_nobody_took_them(): void
    {
        Notification::fake();
        [, , $order] = $this->pendingOrder(2);
        app(ReleaseOrder::class)->handle($order, OrderStatus::Expired);

        $this->postJson(self::URL, $this->notificationFor($order))->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(2, ShowtimeSeat::where('order_id', $order->id)->where('status', SeatStatus::Sold->value)->count());
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->active()->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_a_late_payment_cannot_take_seats_that_someone_else_now_holds(): void
    {
        Notification::fake();
        [, $showtime, $late] = $this->pendingOrder(2);
        app(ReleaseOrder::class)->handle($late, OrderStatus::Expired);

        // Another buyer gets the same two seats while the first buyer's payment was in flight.
        $second = $this->placeOrder(User::factory()->create(), $showtime, 2);

        $this->postJson(self::URL, $this->notificationFor($late))->assertOk();

        // The payment is on record (so it can be refunded) but no ticket exists for it.
        $this->assertSame(OrderStatus::Expired, $late->fresh()->status);
        $this->assertSame(1, Payment::where('order_id', $late->id)->count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(OrderStatus::Pending, $second->fresh()->status);
        $this->assertSame(2, ShowtimeSeat::where('order_id', $second->id)->count());
        Notification::assertNothingSent();
    }
}
