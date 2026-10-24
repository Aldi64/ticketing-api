<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A showtime as the public sees it. Needs venue, sectionPrices.section and the
 * seats_total / seats_available counts to be loaded.
 *
 * @mixin \App\Models\Showtime
 */
class PublicShowtimeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at,
            'venue' => $this->whenLoaded('venue', fn () => [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
                'city' => $this->venue->city,
                'address' => $this->venue->address,
            ]),
            'prices' => $this->whenLoaded('sectionPrices', fn () => $this->sectionPrices->map(fn ($price) => [
                'seat_section_id' => $price->seat_section_id,
                'section_name' => $price->section->name,
                'price' => $price->price,
            ])->values()),
            'availability' => $this->when(isset($this->seats_total), fn () => [
                'total' => $this->seats_total,
                'available' => $this->seats_available,
                'sold_out' => $this->seats_available === 0,
            ]),
        ];
    }
}
