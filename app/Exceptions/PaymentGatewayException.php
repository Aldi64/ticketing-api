<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** The payment provider could not be reached or refused the request. Rendered as 502. */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(string $message = 'The payment service is unavailable right now. Your seats were released, please try again.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 502);
    }
}
