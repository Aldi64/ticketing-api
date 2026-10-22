<?php

namespace App\Policies;

use App\Models\Showtime;
use App\Models\User;

/** A showtime belongs to whoever owns its event. */
class ShowtimePolicy
{
    public function update(User $user, Showtime $showtime): bool
    {
        return (int) $showtime->event->organizer_id === (int) $user->id;
    }
}
