<?php

namespace Tests\Feature\Orders;

use App\Actions\CreateOrder;
use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrders;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use CreatesOrders, CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMidtrans();
    }

    public function test_guests_cannot_use_orders(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();

        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertUnauthorized();
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/orders/ABC')->assertUnauthorized();
        $this->postJson('/api/orders/ABC/cancel')->assertUnauthorized();
    }

    public function test_held_seats_become_a_pending_order_with_a_snap_token(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $seatIds = $this->holdSeats($user, $showtime, 2);
        Sanctum::actingAs($user);

        $this->postJson("/api/showtimes/{$showtime->id}/orders")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_amount', 300000)
            ->assertJsonPath('data.snap_token', 'snap-token-123')
            ->assertJsonPath('data.client_key', 'test-client-key')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.seat_label', 'VIP-A1')
            ->assertJsonPath('data.items.0.price', 150000)
            ->assertJsonPath('data.items.0.ticket', null);

        $order = Order::firstOrFail();
        $this->assertSame($user->id, $order->user_id);
        $this->assertStringStartsWith('TKT-', $order->midtrans_order_id);
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->active()->count());

        // The seats now belong to the order and stay held until it expires.
        $seats = ShowtimeSeat::whereIn('id', $seatIds)->get();
        $this->assertTrue($seats->every(
            fn ($seat) => $seat->order_id === $order->id
                && $seat->status === SeatStatus::Held
                && $seat->held_until->equalTo($order->expires_at)
        ));
        $this->assertEqualsWithDelta(CreateOrder::ORDER_MINUTES * 60, now()->diffInSeconds($order->expires_at), 5);
    }

    public function test_midtrans_is_called_with_the_right_amounts_and_credentials(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $order = $this->placeOrder(User::factory()->create(), $showtime, 3);

        Http::assertSent(function (Request $request) use ($order) {
            return $request->url() === config('midtrans.snap_url')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-server-key:'))
                && $request['transaction_details']['order_id'] === $order->midtrans_order_id
                && $request['transaction_details']['gross_amount'] === 450000
                && collect($request['item_details'])->sum('price') === 450000
                && count($request['item_details']) === 3
                && $request['expiry']['duration'] === CreateOrder::ORDER_MINUTES;
        });
    }

    public function test_without_held_seats_there_is_nothing_to_order(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/showtimes/{$showtime->id}/orders")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seats');

        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_an_expired_hold_cannot_be_ordered(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $seatIds = $this->holdSeats($user, $showtime, 2);
        ShowtimeSeat::whereIn('id', $seatIds)->update(['held_until' => now()->subSecond()]);
        Sanctum::actingAs($user);

        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertUnprocessable();

        $this->assertSame(0, Order::count());
    }

    public function test_someone_elses_hold_cannot_be_ordered(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $owner = User::factory()->create();
        $seatIds = $this->holdSeats($owner, $showtime, 2);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertUnprocessable();

        $this->assertSame(0, Order::count());
        $this->assertSame(2, ShowtimeSeat::whereIn('id', $seatIds)->where('held_by', $owner->id)->whereNull('order_id')->count());
    }

    public function test_ordering_twice_does_not_create_a_second_order(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $this->holdSeats($user, $showtime, 2);
        Sanctum::actingAs($user);

        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertCreated();
        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertUnprocessable();

        $this->assertSame(1, Order::count());
    }

    public function test_a_showtime_that_is_not_bookable_is_a_404(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $this->holdSeats($user, $showtime, 1);
        $showtime->update(['status' => ShowtimeStatus::Cancelled]);
        Sanctum::actingAs($user);

        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertNotFound();
    }

    public function test_when_midtrans_fails_the_order_is_dropped_and_the_seats_are_freed(): void
    {
        // A fresh fake: stubs from setUp() would otherwise match first.
        Http::swap(new Factory);
        Http::fake(['*midtrans.com/*' => Http::response(['error_messages' => ['boom']], 500)]);

        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $seatIds = $this->holdSeats($user, $showtime, 2);
        Sanctum::actingAs($user);

        $this->postJson("/api/showtimes/{$showtime->id}/orders")->assertStatus(502);

        $order = Order::firstOrFail();
        $this->assertSame(OrderStatus::Failed, $order->status);
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->active()->count());
        $this->assertSame(2, ShowtimeSeat::whereIn('id', $seatIds)
            ->where('status', SeatStatus::Available->value)->whereNull('order_id')->whereNull('held_by')->count());
    }

    public function test_a_user_sees_only_their_own_orders(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->placeOrder($me, $showtime, 2);
        $this->placeOrder($other, $showtime, 2, skip: 2);

        Sanctum::actingAs($me);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $mine->code)
            ->assertJsonPath('data.0.showtime.event.title', $showtime->event->title);

        $this->getJson("/api/orders/{$mine->code}")->assertOk()->assertJsonPath('data.code', $mine->code);

        $theirs = Order::where('user_id', $other->id)->firstOrFail();
        $this->getJson("/api/orders/{$theirs->code}")->assertNotFound();
        $this->getJson('/api/orders/DOESNOTEXIST')->assertNotFound();
    }

    public function test_the_snap_token_is_only_shown_while_the_order_is_pending(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $showtime, 1);
        Sanctum::actingAs($user);

        $this->getJson("/api/orders/{$order->code}")->assertJsonPath('data.snap_token', 'snap-token-123');

        Order::whereKey($order->id)->update(['status' => OrderStatus::Cancelled->value]);

        $this->getJson("/api/orders/{$order->code}")
            ->assertOk()
            ->assertJsonMissingPath('data.snap_token')
            ->assertJsonMissingPath('data.client_key');
    }

    public function test_a_pending_order_can_be_cancelled_which_frees_the_seats(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $showtime, 2);
        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->code}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, OrderItem::where('order_id', $order->id)->active()->count());
        $this->assertSame(0, ShowtimeSeat::where('status', SeatStatus::Held->value)->count());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/{$order->midtrans_order_id}/cancel"));
    }

    public function test_paid_and_foreign_orders_cannot_be_cancelled(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $me = User::factory()->create();
        $paid = Order::factory()->paid()->create(['user_id' => $me->id, 'showtime_id' => $showtime->id]);
        $theirs = $this->placeOrder(User::factory()->create(), $showtime, 1);

        Sanctum::actingAs($me);

        $this->postJson("/api/orders/{$paid->code}/cancel")->assertStatus(409);
        $this->postJson("/api/orders/{$theirs->code}/cancel")->assertNotFound();

        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $theirs->fresh()->status);
    }
}
