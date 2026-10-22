<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\ReviewEvent;
use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectEventRequest;
use App\Http\Resources\AdminEventResource;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class EventReviewController extends Controller
{
    /** The review queue. Defaults to events waiting for review, oldest submission first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(EventStatus::class)],
        ]);

        $events = Event::query()
            ->where('status', $request->query('status', EventStatus::PendingReview->value))
            ->with('organizer')
            ->withCount('showtimes')
            ->oldest('updated_at')
            ->paginate(15);

        return AdminEventResource::collection($events);
    }

    public function show(Event $event): AdminEventResource
    {
        $event->load([
            'organizer',
            'showtimes' => fn ($query) => $query->with(['venue', 'sectionPrices'])->orderBy('starts_at'),
        ])->loadCount('showtimes');

        return AdminEventResource::make($event);
    }

    public function approve(Event $event, ReviewEvent $review, Request $request): AdminEventResource
    {
        $event = $review->approve($event, $request->user());

        return AdminEventResource::make($event->load('organizer'));
    }

    public function reject(RejectEventRequest $request, Event $event, ReviewEvent $review): AdminEventResource
    {
        $event = $review->reject($event, $request->user(), $request->validated('reason'));

        return AdminEventResource::make($event->load('organizer'));
    }
}
