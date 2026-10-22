<?php

namespace App\Http\Requests\Organizer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShowtimeRequest extends FormRequest
{
    /** Checked here so a non-owner gets 403 before any validation errors. */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The venue must belong to the logged-in organizer.
            'venue_id' => [
                'required',
                'integer',
                Rule::exists('venues', 'id')->where('organizer_id', $this->user()->id),
            ],
            'starts_at' => ['required', 'date', 'after:now'],
            // One price per section. The action checks that every section with seats is priced.
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.seat_section_id' => ['required', 'integer', 'distinct'],
            'prices.*.price' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }
}
