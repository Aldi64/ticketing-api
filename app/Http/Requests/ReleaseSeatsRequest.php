<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseSeatsRequest extends FormRequest
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
            // Optional: leave it out to release every seat the user holds for this showtime.
            'seat_ids' => ['sometimes', 'array', 'min:1'],
            'seat_ids.*' => ['integer', 'distinct'],
        ];
    }
}
