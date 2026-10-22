<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

/**
 * Events belong to the organizer who created them. These checks only cover ownership;
 * state rules (for example "published events cannot be edited") are handled in the controller.
 */
class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    private function owns(User $user, Event $event): bool
    {
        return (int) $event->organizer_id === (int) $user->id;
    }
}
