<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Illuminate\Notifications\DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->data['type'] ?? null,
            // Not named "data": Laravel skips its own {"data": ...} wrapper when the
            // resource already contains a key called "data".
            'payload' => $this->data,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
