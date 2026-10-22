<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Venue;

/**
 * Venues belong to the organizer who created them. Laravel finds this policy
 * automatically because it follows the Model + "Policy" naming convention.
 */
class VenuePolicy
{
    public function view(User $user, Venue $venue): bool
    {
        return $this->owns($user, $venue);
    }

    public function update(User $user, Venue $venue): bool
    {
        return $this->owns($user, $venue);
    }

    public function delete(User $user, Venue $venue): bool
    {
        return $this->owns($user, $venue);
    }

    private function owns(User $user, Venue $venue): bool
    {
        return (int) $venue->organizer_id === (int) $user->id;
    }
}
