<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\EventStatus;
use App\Enums\ShowtimeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\StoreEventRequest;
use App\Http\Requests\Organizer\UpdateEventRequest;
use App\Http\Requests\Organizer\UploadPosterRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(EventStatus::class)],
        ]);

        $events = $request->user()->events()
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->withCount('showtimes')
            ->latest()
            ->paginate(15);

        return EventResource::collection($events);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = $request->user()->events()->create([
            ...$request->validated(),
            'slug' => $this->uniqueSlug($request->validated('title')),
        ]);

        return EventResource::make($event)->response()->setStatusCode(201);
    }

    public function show(Event $event): EventResource
    {
        Gate::authorize('view', $event);

        $event->load(['showtimes' => fn ($query) => $query->with('venue')->orderBy('starts_at')])
            ->loadCount('showtimes');

        return EventResource::make($event);
    }

    public function update(UpdateEventRequest $request, Event $event): EventResource
    {
        Gate::authorize('update', $event);
        $this->ensureEditable($event);

        $event->fill($request->validated());

        // Editing a rejected event reopens it as a draft, ready to be fixed and resubmitted.
        if ($event->status === EventStatus::Rejected) {
            $this->resetReview($event, EventStatus::Draft);
        }

        $event->save();

        return EventResource::make($event);
    }

    public function destroy(Event $event): Response
    {
        Gate::authorize('delete', $event);

        $hasOrders = Order::query()
            ->whereIn('showtime_id', $event->showtimes()->select('id'))
            ->exists();

        if ($hasOrders) {
            abort(409, 'This event already has orders and cannot be deleted.');
        }

        if ($event->poster_path) {
            Storage::disk('public')->delete($event->poster_path);
        }

        $event->delete();

        return response()->noContent();
    }

    /** Sends a draft or rejected event to the admins for review. */
    public function submit(Event $event): EventResource
    {
        Gate::authorize('update', $event);
        $this->ensureEditable($event);

        $hasUpcomingShowtime = $event->showtimes()
            ->where('status', ShowtimeStatus::Scheduled->value)
            ->where('starts_at', '>', now())
            ->exists();

        if (! $hasUpcomingShowtime) {
            throw ValidationException::withMessages([
                'showtimes' => 'Add at least one upcoming showtime before submitting for review.',
            ]);
        }

        $this->resetReview($event, EventStatus::PendingReview);
        $event->save();

        return EventResource::make($event);
    }

    public function poster(UploadPosterRequest $request, Event $event): EventResource
    {
        $oldPath = $event->poster_path;

        $event->update([
            'poster_path' => $request->file('poster')->store('posters', 'public'),
        ]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return EventResource::make($event);
    }

    private function ensureEditable(Event $event): void
    {
        if (! in_array($event->status, [EventStatus::Draft, EventStatus::Rejected], true)) {
            abort(409, 'Only draft or rejected events can be changed.');
        }
    }

    /** Moves the event to a new status and clears the previous review outcome. */
    private function resetReview(Event $event, EventStatus $status): void
    {
        $event->status = $status;
        $event->rejection_reason = null;
        $event->reviewed_by = null;
        $event->reviewed_at = null;
    }

    private function uniqueSlug(string $title): string
    {
        return Str::slug($title).'-'.Str::lower(Str::random(6));
    }
}
