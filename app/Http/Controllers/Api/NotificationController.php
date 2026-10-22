<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The logged-in user's in-app notifications (the bell icon in the frontend). */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return NotificationResource::collection($user->notifications()->latest()->paginate(20))
            ->additional(['unread_count' => $user->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): NotificationResource
    {
        // Looked up through the user's own notifications, so someone else's id is a 404.
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return NotificationResource::make($notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }
}
