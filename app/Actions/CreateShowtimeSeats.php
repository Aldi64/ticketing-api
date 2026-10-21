<?php

namespace App\Actions;

use App\Enums\SeatStatus;
use App\Models\Seat;
use App\Models\SeatSection;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use App\Models\ShowtimeSectionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Generates the bookable seat rows for a showtime by copying the venue's active
 * seats into showtime_seats, priced by section. Runs once per showtime.
 */
class CreateShowtimeSeats
{
    /**
     * @param  array<int, int>  $sectionPrices  seat_section_id => price in rupiah
     * @return int number of showtime_seats created
     */
    public function handle(Showtime $showtime, array $sectionPrices): int
    {
        if ($showtime->seats()->exists()) {
            throw new LogicException('Seats were already generated for this showtime.');
        }

        $this->validatePrices($showtime, $sectionPrices);

        return DB::transaction(function () use ($showtime, $sectionPrices) {
            foreach ($sectionPrices as $sectionId => $price) {
                ShowtimeSectionPrice::create([
                    'showtime_id' => $showtime->id,
                    'seat_section_id' => $sectionId,
                    'price' => $price,
                ]);
            }

            $now = now();
            $created = 0;

            Seat::query()
                ->where('venue_id', $showtime->venue_id)
                ->where('is_active', true)
                ->chunkById(500, function ($seats) use ($showtime, $sectionPrices, $now, &$created) {
                    $rows = $seats->map(fn (Seat $seat) => [
                        'showtime_id' => $showtime->id,
                        'seat_id' => $seat->id,
                        'price' => $sectionPrices[$seat->seat_section_id],
                        'status' => SeatStatus::Available->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    ShowtimeSeat::insert($rows);
                    $created += count($rows);
                });

            return $created;
        });
    }

    /**
     * @param  array<int, int>  $sectionPrices
     */
    private function validatePrices(Showtime $showtime, array $sectionPrices): void
    {
        $pricedIds = array_map('intval', array_keys($sectionPrices));

        $venueSectionIds = SeatSection::query()
            ->where('venue_id', $showtime->venue_id)
            ->pluck('id')
            ->all();

        if (array_diff($pricedIds, $venueSectionIds) !== []) {
            throw ValidationException::withMessages([
                'prices' => 'One or more sections do not belong to this showtime\'s venue.',
            ]);
        }

        $sectionsWithSeats = Seat::query()
            ->where('venue_id', $showtime->venue_id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('seat_section_id')
            ->all();

        if (array_diff($sectionsWithSeats, $pricedIds) !== []) {
            throw ValidationException::withMessages([
                'prices' => 'Every section that has seats needs a price.',
            ]);
        }

        foreach ($sectionPrices as $price) {
            if (! is_int($price) || $price < 1) {
                throw ValidationException::withMessages([
                    'prices' => 'Prices must be whole rupiah amounts greater than zero.',
                ]);
            }
        }
    }
}
