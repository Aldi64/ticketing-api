<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pending = $this->status === OrderStatus::Pending;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            // Only a pending order can still be paid, so only then is the token useful.
            'snap_token' => $this->when($pending, $this->snap_token),
            'client_key' => $this->when($pending, fn () => config('midtrans.client_key')),
            'snap_js_url' => $this->when($pending, fn () => config('midtrans.snap_js_url')),
            'showtime' => $this->whenLoaded('showtime', fn () => [
                'id' => $this->showtime->id,
                'starts_at' => $this->showtime->starts_at,
                'event' => $this->showtime->relationLoaded('event') ? [
                    'id' => $this->showtime->event->id,
                    'title' => $this->showtime->event->title,
                    'slug' => $this->showtime->event->slug,
                ] : null,
                'venue' => $this->showtime->relationLoaded('venue') ? [
                    'name' => $this->showtime->venue->name,
                    'city' => $this->showtime->venue->city,
                ] : null,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'seat_label' => $item->seat_label,
                'price' => $item->price,
                'ticket' => $item->relationLoaded('ticket') && $item->ticket ? [
                    'code' => $item->ticket->code,
                    'checked_in_at' => $item->ticket->checked_in_at,
                ] : null,
            ])->values()),
        ];
    }
}
