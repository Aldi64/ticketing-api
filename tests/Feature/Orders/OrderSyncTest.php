<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrders;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\Concerns\CreatesPublishedEvents;
use Tests\TestCase;

class OrderSyncTest extends TestCase
{
    use CreatesOrders, CreatesOrganizerFixtures, CreatesPublishedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMidtrans();
    }

    /** Replaces the Midtrans fake so the status endpoint answers with $status. */
    private function midtransReports(Order $order, array $status): void
    {
        Http::swap(new Factory);
        Http::fake(['*/status' => Http::response($status)]);
    }

    private function pendingOrderFor(User $user): Order
    {
        [, $showtime] = $this->publishedEventWithShowtime();

        return $this->placeOrder($user, $showtime, 2);
    }

    public function test_sync_pays_the_order_when_midtrans_reports_a_settlement(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $order = $this->pendingOrderFor($user);
        $this->midtransReports($order, $this->notificationFor($order, 'settlement'));
        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->code}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(2, Ticket::count());
    }

    public function test_sync_is_idempotent_and_agrees_with_the_webhook(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $order = $this->pendingOrderFor($user);
        $payload = $this->notificationFor($order, 'settlement');
        $this->midtransReports($order, $payload);
        Sanctum::actingAs($user);

        $this->postJson('/api/payments/midtrans/notification', $payload)->assertOk();
        $this->postJson("/api/orders/{$order->code}/sync")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/orders/{$order->code}/sync")->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(2, Ticket::count());
    }

    public function test_sync_changes_nothing_while_the_buyer_has_not_paid_yet(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrderFor($user);
        // What Midtrans answers before a payment method is chosen.
        $this->midtransReports($order, ['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->code}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_sync_survives_midtrans_being_down(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrderFor($user);
        Http::swap(new Factory);
        Http::fake(['*/status' => Http::response('oops', 500)]);
        Sanctum::actingAs($user);

        $this->postJson("/api/orders/{$order->code}/sync")->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_sync_only_works_on_your_own_orders(): void
    {
        $order = $this->pendingOrderFor(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/orders/{$order->code}/sync")->assertNotFound();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_guests_cannot_sync(): void
    {
        $this->postJson('/api/orders/ABC/sync')->assertUnauthorized();
    }
}
