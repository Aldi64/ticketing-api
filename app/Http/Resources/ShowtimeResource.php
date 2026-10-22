<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Showtime
 */
class ShowtimeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'venue_id' => $this->venue_id,
            'starts_at' => $this->starts_at,
            'status' => $this->status->value,
            'venue' => $this->whenLoaded('venue', fn () => [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
                'city' => $this->venue->city,
            ]),
            'prices' => $this->whenLoaded('sectionPrices', fn () => $this->sectionPrices->map(fn ($price) => [
                'seat_section_id' => $price->seat_section_id,
                'price' => $price->price,
            ])),
            // Present only when the query used withCount() for the seat totals.
            'seat_counts' => $this->when(isset($this->seats_total), fn () => [
                'total' => $this->seats_total,
                'available' => $this->seats_available,
                'held' => $this->seats_held,
                'sold' => $this->seats_sold,
            ]),
        ];
    }
}
