<?php

namespace App\Actions;

use App\Enums\SeatStatus;
use App\Enums\ShowtimeStatus;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Temporarily reserves seats for a user so they can pay.
 *
 * All-or-nothing: either every requested seat is held, or none are and a
 * SeatsUnavailableException lists the seats that were taken.
 *
 * Concurrency: the rows are locked with SELECT ... FOR UPDATE, always in id order,
 * so two requests for overlapping seats can't deadlock, and the second request
 * re-reads the winner's committed state once the lock is released.
 */
class HoldSeats
{
    public const MAX_SEATS = 6;

    public const HOLD_MINUTES = 10;

    public function __construct(private readonly ExpireStaleOrders $expireStaleOrders) {}

    /**
     * @param  array<int, int|string>  $showtimeSeatIds  showtime_seats ids
     * @return Collection<int, ShowtimeSeat> the held seats, with seat and section loaded
     *
     * @throws ValidationException
     * @throws SeatsUnavailableException
     */
    public function handle(User $user, Showtime $showtime, array $showtimeSeatIds): Collection
    {
        $ids = collect($showtimeSeatIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === [] || count($ids) > self::MAX_SEATS) {
            throw ValidationException::withMessages([
                'seat_ids' => 'Select between 1 and '.self::MAX_SEATS.' seats.',
            ]);
        }

        if ($showtime->status !== ShowtimeStatus::Scheduled || $showtime->starts_at->isPast()) {
            throw ValidationException::withMessages([
                'showtime' => 'This showtime is not open for booking.',
            ]);
        }

        // Seats of an unpaid order whose time is up are freed first, so their old order
        // can't still own them (and block the partial unique index) when we take them.
        $this->expireStaleOrders->handle($showtime);

        $heldUntil = now()->addMinutes(self::HOLD_MINUTES);

        DB::transaction(function () use ($user, $showtime, $ids, $heldUntil) {
            $seats = ShowtimeSeat::query()
                ->where('showtime_id', $showtime->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($seats->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'seat_ids' => 'One or more seats do not belong to this showtime.',
                ]);
            }

            $unavailable = $seats->reject(fn (ShowtimeSeat $seat) => $this->canHold($seat, $user));

            if ($unavailable->isNotEmpty()) {
                throw new SeatsUnavailableException($unavailable->pluck('id')->all());
            }

            ShowtimeSeat::query()->whereIn('id', $ids)->update([
                'status' => SeatStatus::Held->value,
                'held_by' => $user->id,
                'held_until' => $heldUntil,
                'order_id' => null,
                'updated_at' => now(),
            ]);
        });

        return ShowtimeSeat::query()
            ->whereIn('id', $ids)
            ->with('seat.section')
            ->orderBy('id')
            ->get();
    }

    /**
     * A seat can be held if it is free (available, or held with an expired hold),
     * or if the same user already holds it and has not turned it into an order yet.
     */
    private function canHold(ShowtimeSeat $seat, User $user): bool
    {
        if ($seat->isTakeable()) {
            return true;
        }

        return $seat->status === SeatStatus::Held
            && (int) $seat->held_by === $user->id
            && $seat->order_id === null;
    }
}
