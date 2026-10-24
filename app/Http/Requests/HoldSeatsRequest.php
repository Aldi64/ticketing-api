<?php

namespace App\Http\Requests;

use App\Actions\HoldSeats;
use Illuminate\Foundation\Http\FormRequest;

class HoldSeatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // These are showtime_seats ids, exactly as returned by the seat map endpoint.
            'seat_ids' => ['required', 'array', 'min:1', 'max:'.HoldSeats::MAX_SEATS],
            'seat_ids.*' => ['integer', 'distinct'],
        ];
    }
}
