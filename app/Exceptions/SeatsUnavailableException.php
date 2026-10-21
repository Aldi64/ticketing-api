<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when one or more requested seats can no longer be held.
 * Rendered as a 409 Conflict that tells the frontend which seats to refresh.
 */
class SeatsUnavailableException extends RuntimeException
{
    /**
     * @param  array<int, int>  $seatIds  showtime_seats ids that could not be held
     */
    public function __construct(public readonly array $seatIds)
    {
        parent::__construct('One or more of the selected seats are no longer available.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'unavailable_seat_ids' => $this->seatIds,
        ], 409);
    }
}
