<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Actions\GenerateSeatGrid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\StoreVenueRequest;
use App\Http\Requests\Organizer\UpdateVenueRequest;
use App\Http\Resources\VenueResource;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class VenueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $venues = $request->user()->venues()
            ->withCount(['sections', 'seats'])
            ->orderBy('name')
            ->get();

        return VenueResource::collection($venues);
    }

    public function store(StoreVenueRequest $request, GenerateSeatGrid $grid): JsonResponse
    {
        $venue = DB::transaction(function () use ($request, $grid) {
            $venue = $request->user()->venues()->create(
                $request->safe()->only(['name', 'city', 'address'])
            );

            $grid->handle($venue, $request->validated('sections'));

            return $venue;
        });

        $venue->load(['sections' => fn ($query) => $query->withCount('seats')])
            ->loadCount(['sections', 'seats']);

        return VenueResource::make($venue)->response()->setStatusCode(201);
    }

    public function show(Venue $venue): VenueResource
    {
        Gate::authorize('view', $venue);

        $venue->load([
            'sections' => fn ($query) => $query->withCount('seats'),
            'seats' => fn ($query) => $query->orderBy('row_label')->orderBy('seat_number'),
        ])->loadCount(['sections', 'seats']);

        return VenueResource::make($venue);
    }

    public function update(UpdateVenueRequest $request, Venue $venue): VenueResource
    {
        Gate::authorize('update', $venue);

        $venue->update($request->validated());
        $venue->loadCount(['sections', 'seats']);

        return VenueResource::make($venue);
    }

    public function destroy(Venue $venue): Response
    {
        Gate::authorize('delete', $venue);

        if ($venue->showtimes()->exists()) {
            abort(409, 'This venue is used by showtimes and cannot be deleted.');
        }

        $venue->delete();

        return response()->noContent();
    }
}
