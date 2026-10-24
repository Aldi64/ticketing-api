<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the buyer their payment went through. Carries plain values, like the other notifications. */
class OrderPaid extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $seats */
    public function __construct(
        public readonly int $orderId,
        public readonly string $code,
        public readonly string $eventTitle,
        public readonly string $startsAt,
        public readonly array $seats,
        public readonly int $total,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your tickets for \"{$this->eventTitle}\"")
            ->greeting("Hello {$notifiable->name},")
            ->line("Payment received for order {$this->code}. Your tickets are ready.")
            ->line("{$this->eventTitle}, {$this->startsAt}")
            ->line('Seats: '.implode(', ', $this->seats))
            ->line('Total: Rp '.number_format($this->total, 0, ',', '.'))
            ->action('View your tickets', config('ticketing.frontend_url').'/orders/'.$this->code);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_paid',
            'order_id' => $this->orderId,
            'code' => $this->code,
            'title' => $this->eventTitle,
            'message' => "Payment received for \"{$this->eventTitle}\". Your tickets are ready.",
        ];
    }
}
