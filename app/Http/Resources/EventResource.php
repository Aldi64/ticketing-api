<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\Event
 */
class EventResource extends JsonResource
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
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'showtimes_count' => $this->whenCounted('showtimes'),
            'showtimes' => ShowtimeResource::collection($this->whenLoaded('showtimes')),
            'created_at' => $this->created_at,
        ];
    }
}
