<?php

namespace Tests\Feature\Seats;

use App\Actions\HoldSeats;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Tests\Concerns\CreatesShowtimeWithSeats;
use Tests\TestCase;
use Throwable;

/**
 * Fires several real OS processes at the same seats at once.
 *
 * It must run against PostgreSQL (see phpunit.xml) and it commits its data, so it uses
 * DatabaseTruncation instead of RefreshDatabase: RefreshDatabase wraps each test in a
 * transaction that the child processes could not see.
 *
 * Process start-up jitter means the requests will not always overlap perfectly, so this
 * is a regression guard for the "exactly one winner" rule, not a mathematical proof.
 */
class HoldSeatsConcurrencyTest extends TestCase
{
    use CreatesShowtimeWithSeats, DatabaseTruncation;

    public function test_exactly_one_of_many_parallel_requests_wins_the_same_seats(): void
    {
        $showtime = $this->makeShowtime();
        $seatIds = $showtime->seats()->orderBy('id')->limit(2)->pluck('id')->all();
        $showtimeId = $showtime->id;

        $tasks = User::factory()->count(5)->create()->map(function (User $user) use ($showtimeId, $seatIds) {
            $userId = $user->id;

            // static: the closure is serialized into a child process and must not capture $this.
            return static function () use ($userId, $showtimeId, $seatIds): string {
                try {
                    app(HoldSeats::class)->handle(
                        User::findOrFail($userId),
                        Showtime::findOrFail($showtimeId),
                        $seatIds,
                    );

                    return 'won';
                } catch (SeatsUnavailableException) {
                    return 'conflict';
                } catch (Throwable $e) {
                    return 'error: '.$e::class.': '.$e->getMessage();
                }
            };
        })->all();

        $results = Concurrency::run($tasks);

        $this->assertSame(1, count(array_keys($results, 'won', true)), json_encode($results));
        $this->assertSame(4, count(array_keys($results, 'conflict', true)), json_encode($results));

        $holders = ShowtimeSeat::query()->whereIn('id', $seatIds)->pluck('held_by')->unique();
        $this->assertCount(1, $holders, 'Both seats must be held by the same single winner.');
    }
}
