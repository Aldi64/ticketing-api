<?php

namespace Tests\Feature\Orders;

use App\Actions\HoldSeats;
use App\Enums\OrderStatus;
use App\Enums\SeatStatus;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrders;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class OrderExpiryTest extends TestCase
{
    use CreatesOrders, CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMidtrans();
    }

    /** Makes the order's payment window run out, the way time passing would. */
    private function timeRunsOut(Order $order): void
    {
        Order::whereKey($order->id)->update(['expires_at' => now()->subMinute()]);
        ShowtimeSeat::where('order_id', $order->id)->update(['held_until' => now()->subMinute()]);
    }

    public function test_the_command_expires_only_overdue_pending_orders(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $overdue = $this->placeOrder(User::factory()->create(), $showtime, 2);
        $fresh = $this->placeOrder(User::factory()->create(), $showtime, 2, skip: 2);
        $paid = Order::factory()->paid()->create(['showtime_id' => $showtime->id, 'expires_at' => now()->subHour()]);

        $this->timeRunsOut($overdue);

        $this->artisan('orders:expire')->expectsOutput('Expired 1 unpaid order(s).')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(0, OrderItem::where('order_id', $overdue->id)->active()->count());
        $this->assertSame(0, ShowtimeSeat::where('order_id', $overdue->id)->count());
        $this->assertSame(2, ShowtimeSeat::where('status', SeatStatus::Available->value)->where('showtime_id', $showtime->id)
            ->whereIn('id', $overdue->items()->pluck('showtime_seat_id'))->count());

        $this->assertSame(OrderStatus::Pending, $fresh->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
    }

    public function test_the_command_is_safe_to_run_again(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $order = $this->placeOrder(User::factory()->create(), $showtime, 1);
        $this->timeRunsOut($order);

        $this->artisan('orders:expire')->assertSuccessful();
        $this->artisan('orders:expire')->expectsOutput('Expired 0 unpaid order(s).')->assertSuccessful();
    }

    public function test_seats_of_an_order_that_is_still_inside_its_window_cannot_be_taken(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $first = User::factory()->create();
        $this->placeOrder($first, $showtime, 2);

        $this->expectException(SeatsUnavailableException::class);

        $this->holdSeats(User::factory()->create(), $showtime, 2);
    }

    public function test_an_unpaid_order_never_blocks_its_seats_after_its_time_is_up(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $abandoned = $this->placeOrder($first, $showtime, 2);

        // The scheduler has NOT run yet, but the payment window is over.
        $this->timeRunsOut($abandoned);

        $newOrder = $this->placeOrder($second, $showtime, 2);

        $this->assertSame(OrderStatus::Expired, $abandoned->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $newOrder->fresh()->status);
        $this->assertSame(2, OrderItem::where('order_id', $newOrder->id)->active()->count());
        $this->assertSame(0, OrderItem::where('order_id', $abandoned->id)->active()->count());
        $this->assertSame(2, ShowtimeSeat::where('held_by', $second->id)->where('order_id', $newOrder->id)->count());
    }

    public function test_holding_seats_does_not_touch_other_showtimes_orders(): void
    {
        [, $showtime] = $this->publishedEventWithShowtime();
        [, $otherShowtime] = $this->publishedEventWithShowtime();
        $elsewhere = $this->placeOrder(User::factory()->create(), $otherShowtime, 1);
        $this->timeRunsOut($elsewhere);

        app(HoldSeats::class)->handle(User::factory()->create(), $showtime, [$showtime->seats()->value('id')]);

        // Only the showtime being booked is cleaned up here; the scheduler handles the rest.
        $this->assertSame(OrderStatus::Pending, $elsewhere->fresh()->status);
    }
}
