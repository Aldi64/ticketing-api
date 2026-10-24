<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProcessMidtransNotification;
use App\Http\Controllers\Controller;
use App\Services\Midtrans\MidtransGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Midtrans payment notifications (set the URL under Settings > Payment >
 * Notification URL in the Midtrans dashboard). It is public, so the signature is
 * checked before anything else is looked at.
 */
class MidtransWebhookController extends Controller
{
    private const REQUIRED = ['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_id', 'transaction_status'];

    public function __invoke(Request $request, MidtransGateway $gateway, ProcessMidtransNotification $process): JsonResponse
    {
        $payload = $request->all();

        foreach (self::REQUIRED as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                return response()->json(['message' => 'Malformed notification.'], 400);
            }
        }

        if (! $gateway->hasValidSignature($payload)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        return match ($process->handle($payload)) {
            ProcessMidtransNotification::UNKNOWN_ORDER => response()->json(['message' => 'Unknown order.'], 404),
            ProcessMidtransNotification::AMOUNT_MISMATCH => response()->json(['message' => 'Amount mismatch.'], 400),
            default => response()->json(['status' => 'ok']),
        };
    }
}
