<?php

namespace App\Actions;

use App\Enums\EventStatus;
use App\Exceptions\EventNotPendingReviewException;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventApproved;
use App\Notifications\EventRejected;
use Illuminate\Support\Facades\DB;

/**
 * Records an admin's decision on an event that is waiting for review.
 *
 * The event row is locked while the decision is saved, so if two admins act at the same
 * moment, the second one sees the new status and gets a 409 instead of overwriting the
 * first decision (and the organizer only gets one email).
 */
class ReviewEvent
{
    public function approve(Event $event, User $admin): Event
    {
        return $this->review($event, $admin, EventStatus::Published, null);
    }

    public function reject(Event $event, User $admin, string $reason): Event
    {
        return $this->review($event, $admin, EventStatus::Rejected, $reason);
    }

    private function review(Event $event, User $admin, EventStatus $decision, ?string $reason): Event
    {
        $reviewed = DB::transaction(function () use ($event, $admin, $decision, $reason) {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::PendingReview) {
                throw new EventNotPendingReviewException($locked->status);
            }

            $locked->status = $decision;
            $locked->rejection_reason = $reason;
            $locked->reviewed_by = $admin->id;
            $locked->reviewed_at = now();
            $locked->save();

            return $locked;
        });

        // Sent after the transaction has committed, and queued, so a slow mail server
        // never delays or fails the review itself.
        $reviewed->organizer->notify(
            $decision === EventStatus::Published
                ? new EventApproved($reviewed->id, $reviewed->title)
                : new EventRejected($reviewed->id, $reviewed->title, (string) $reason)
        );

        return $reviewed;
    }
}
