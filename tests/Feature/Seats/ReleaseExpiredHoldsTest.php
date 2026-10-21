<?php

namespace Tests\Feature\Seats;

use App\Enums\SeatStatus;
use App\Models\Order;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShowtimeWithSeats;
use Tests\TestCase;

class ReleaseExpiredHoldsTest extends TestCase
{
    use CreatesShowtimeWithSeats, RefreshDatabase;

    public function test_it_releases_only_expired_holds_without_an_order(): void
    {
        $user = User::factory()->create();
        $showtime = $this->makeShowtime();
        $order = Order::factory()->create(['showtime_id' => $showtime->id, 'user_id' => $user->id]);

        [$expired, $active, $sold, $expiredWithOrder] = $showtime->seats()->orderBy('id')->limit(4)->pluck('id')->all();

        $hold = fn (int $id, array $extra = []) => ShowtimeSeat::query()->whereKey($id)->update([
            'status' => SeatStatus::Held->value,
            'held_by' => $user->id,
            'held_until' => now()->subMinutes(5),
            ...$extra,
        ]);

        $hold($expired);
        $hold($active, ['held_until' => now()->addMinutes(5)]);
        $hold($expiredWithOrder, ['order_id' => $order->id]);
        ShowtimeSeat::query()->whereKey($sold)->update(['status' => SeatStatus::Sold->value]);

        $this->artisan('seats:release-expired')->assertSuccessful();

        $released = ShowtimeSeat::find($expired);
        $this->assertSame(SeatStatus::Available, $released->status);
        $this->assertNull($released->held_by);
        $this->assertNull($released->held_until);

        $this->assertSame(SeatStatus::Held, ShowtimeSeat::find($active)->status);
        $this->assertSame(SeatStatus::Held, ShowtimeSeat::find($expiredWithOrder)->status);
        $this->assertSame(SeatStatus::Sold, ShowtimeSeat::find($sold)->status);
    }
}
