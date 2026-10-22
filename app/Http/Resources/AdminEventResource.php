<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** The event as an admin sees it: the normal fields plus who owns it and who reviewed it. */
class AdminEventResource extends EventResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
                'email' => $this->organizer->email,
            ]),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
        ]);
    }
}
