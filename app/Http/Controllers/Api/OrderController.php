<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateOrder;
use App\Actions\ProcessMidtransNotification;
use App\Actions\ReleaseOrder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Midtrans\MidtransGateway;
use App\Support\BookableShowtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    /** The logged-in user's orders, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.ticket', 'showtime.event', 'showtime.venue'])
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $code): OrderResource
    {
        return new OrderResource($this->findMine($request, $code));
    }

    /** Turns the user's held seats for a showtime into an order and returns the Snap token. */
    public function store(Request $request, string $showtime, CreateOrder $createOrder): JsonResponse
    {
        $showtime = BookableShowtime::findOrFail($showtime);

        $order = $createOrder->handle($request->user(), $showtime);

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    /** Lets the buyer back out of an unpaid order, which frees the seats. */
    public function cancel(Request $request, string $code, ReleaseOrder $release, MidtransGateway $gateway): JsonResponse|OrderResource
    {
        $order = $this->findMine($request, $code);

        if (! $release->handle($order, OrderStatus::Cancelled)) {
            return response()->json(['message' => 'Only a pending order can be cancelled.'], 409);
        }

        $gateway->cancel($order);

        return new OrderResource($this->findMine($request, $code));
    }

    /**
     * Pulls the payment status from Midtrans and applies it, exactly like a webhook would.
     * The frontend calls this after the Snap popup closes, so the page does not depend on
     * the webhook arriving first. Safe to call repeatedly.
     */
    public function sync(Request $request, string $code, MidtransGateway $gateway, ProcessMidtransNotification $process): OrderResource
    {
        $order = $this->findMine($request, $code);

        if ($order->isPending() && ($payload = $gateway->fetchStatus($order)) !== null) {
            $process->handle($payload);
            $order = $this->findMine($request, $code);
        }

        return new OrderResource($order);
    }

    private function findMine(Request $request, string $code): Order
    {
        return Order::query()
            ->where('user_id', $request->user()->id)
            ->where('code', $code)
            ->with(['items.ticket', 'showtime.event', 'showtime.venue'])
            ->firstOrFail();
    }
}
