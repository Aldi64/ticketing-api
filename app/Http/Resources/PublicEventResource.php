<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An event as the public sees it. No review fields and no organizer details.
 *
 * @mixin \App\Models\Event
 */
class PublicEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => $this->category->value,
            'description' => $this->description,
            'poster_url' => $this->poster_path ? Storage::disk('public')->url($this->poster_path) : null,
            // These two come from subqueries in the list endpoint, so the detail endpoint omits them.
            'next_showtime_at' => $this->when(
                isset($this->next_showtime_at),
                fn () => Carbon::parse($this->next_showtime_at)
            ),
            'min_price' => $this->when(isset($this->min_price), fn () => (int) $this->min_price),
            'showtimes' => PublicShowtimeResource::collection($this->whenLoaded('showtimes')),
        ];
    }
}
