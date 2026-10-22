<?php

namespace App\Exceptions;

use App\Enums\EventStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Thrown when an admin tries to review an event that is not waiting for review. Rendered as 409. */
class EventNotPendingReviewException extends RuntimeException
{
    public function __construct(public readonly EventStatus $currentStatus)
    {
        parent::__construct('Only events pending review can be approved or rejected.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_status' => $this->currentStatus->value,
        ], 409);
    }
}
