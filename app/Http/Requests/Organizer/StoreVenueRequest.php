<?php

namespace App\Http\Requests\Organizer;

use App\Actions\GenerateSeatGrid;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreVenueRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'sections' => ['required', 'array', 'min:1', 'max:10'],
            'sections.*.name' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'sections.*.rows' => ['required', 'integer', 'min:1', 'max:'.GenerateSeatGrid::MAX_ROWS],
            'sections.*.seats_per_row' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $totalRows = array_sum(array_column($this->input('sections', []), 'rows'));

            if ($totalRows > GenerateSeatGrid::MAX_ROWS) {
                $validator->errors()->add(
                    'sections',
                    'A venue can have at most '.GenerateSeatGrid::MAX_ROWS.' rows in total.'
                );
            }
        });
    }
}
