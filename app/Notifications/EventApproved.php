<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an organizer their event was approved. It carries plain values instead of the
 * Event model, so the queued job never breaks if the event is deleted before it runs.
 */
class EventApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $title,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your event \"{$this->title}\" was approved")
            ->greeting("Hello {$notifiable->name},")
            ->line("Good news: \"{$this->title}\" has been approved and is now published.")
            ->line('Attendees can find it and start buying tickets.')
            ->action('View your event', config('ticketing.frontend_url').'/organizer/events/'.$this->eventId);
    }

    /** @return array<string, mixed> stored in the notifications table */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'event_approved',
            'event_id' => $this->eventId,
            'title' => $this->title,
            'message' => "\"{$this->title}\" was approved and is now published.",
        ];
    }
}
