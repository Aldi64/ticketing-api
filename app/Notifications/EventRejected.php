<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells an organizer their event was rejected, with the admin's reason. */
class EventRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $title,
        public readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your event \"{$this->title}\" needs changes")
            ->greeting("Hello {$notifiable->name},")
            ->line("\"{$this->title}\" was not approved. The reviewer left this note:")
            ->line($this->reason)
            ->line('You can edit the event, fix the issue and submit it for review again.')
            ->action('Edit your event', config('ticketing.frontend_url').'/organizer/events/'.$this->eventId);
    }

    /** @return array<string, mixed> stored in the notifications table */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'event_rejected',
            'event_id' => $this->eventId,
            'title' => $this->title,
            'reason' => $this->reason,
            'message' => "\"{$this->title}\" was not approved: {$this->reason}",
        ];
    }
}
