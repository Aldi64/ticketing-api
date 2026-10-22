<?php

namespace App\Actions;

use App\Models\Seat;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds a venue's seat layout from simple section specs.
 *
 * Rows are lettered continuously across sections, front to back: a VIP section with
 * 2 rows takes A and B, and the next section with 3 rows takes C, D and E. Seat
 * numbers run from 1 within each row.
 */
class GenerateSeatGrid
{
    public const MAX_ROWS = 26;

    /**
     * @param  array<int, array{name: string, rows: int, seats_per_row: int}>  $sections
     */
    public function handle(Venue $venue, array $sections): void
    {
        $totalRows = array_sum(array_column($sections, 'rows'));

        if ($totalRows > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'sections' => 'A venue can have at most '.self::MAX_ROWS.' rows in total.',
            ]);
        }

        DB::transaction(function () use ($venue, $sections) {
            $rowIndex = 0;
            $now = now();

            foreach ($sections as $spec) {
                $section = $venue->sections()->create(['name' => $spec['name']]);

                $seats = [];
                for ($r = 0; $r < $spec['rows']; $r++) {
                    $label = chr(65 + $rowIndex++);

                    for ($number = 1; $number <= $spec['seats_per_row']; $number++) {
                        $seats[] = [
                            'venue_id' => $venue->id,
                            'seat_section_id' => $section->id,
                            'row_label' => $label,
                            'seat_number' => $number,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($seats, 500) as $chunk) {
                    Seat::insert($chunk);
                }
            }
        });
    }
}
