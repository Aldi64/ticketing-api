<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Venue
 */
class VenueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'address' => $this->address,
            'sections_count' => $this->whenCounted('sections'),
            'seats_count' => $this->whenCounted('seats'),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'name' => $section->name,
                'seats_count' => $section->seats_count,
            ])),
            'seats' => $this->whenLoaded('seats', fn () => $this->seats->map(fn ($seat) => [
                'id' => $seat->id,
                'seat_section_id' => $seat->seat_section_id,
                'row_label' => $seat->row_label,
                'seat_number' => $seat->seat_number,
                'is_active' => $seat->is_active,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
