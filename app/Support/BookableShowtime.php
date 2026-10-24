<?php

namespace App\Support;

use App\Enums\ShowtimeStatus;
use App\Models\Showtime;

/**
 * The single rule for "can the public see and book this showtime?": it is scheduled,
 * has not started, and belongs to a published event. Anything else is a plain 404, so
 * draft or cancelled showtimes can't be discovered by guessing ids.
 */
final class BookableShowtime
{
    public static function findOrFail(int|string $id): Showtime
    {
        return Showtime::query()
            ->whereKey($id)
            ->where('status', ShowtimeStatus::Scheduled->value)
            ->where('starts_at', '>', now())
            ->whereHas('event', fn ($query) => $query->published())
            ->with(['event', 'venue'])
            ->firstOrFail();
    }
}
