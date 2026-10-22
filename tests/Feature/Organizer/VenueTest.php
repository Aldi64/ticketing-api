<?php

namespace Tests\Feature\Organizer;

use App\Models\Event;
use App\Models\Seat;
use App\Models\Showtime;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\TestCase;

class VenueTest extends TestCase
{
    use CreatesOrganizerFixtures, RefreshDatabase;

    private function validVenue(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jakarta Concert Hall',
            'city' => 'Jakarta',
            'address' => 'Jl. Sudirman No. 1',
            'sections' => [
                ['name' => 'VIP', 'rows' => 2, 'seats_per_row' => 5],
                ['name' => 'Regular', 'rows' => 3, 'seats_per_row' => 4],
            ],
        ], $overrides);
    }

    public function test_an_organizer_creates_a_venue_with_a_generated_seat_grid(): void
    {
        $organizer = $this->makeOrganizer();
        Sanctum::actingAs($organizer);

        $this->postJson('/api/organizer/venues', $this->validVenue())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jakarta Concert Hall')
            ->assertJsonPath('data.sections_count', 2)
            ->assertJsonPath('data.seats_count', 22);

        $venue = Venue::firstOrFail();
        $this->assertEquals($organizer->id, $venue->organizer_id);
        $this->assertSame(22, Seat::count());
    }

    public function test_rows_are_lettered_continuously_across_sections(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson('/api/organizer/venues', $this->validVenue())->assertCreated();

        $rowsOf = fn (string $section) => Seat::query()
            ->whereHas('section', fn ($query) => $query->where('name', $section))
            ->pluck('row_label')->unique()->sort()->values()->all();

        $this->assertSame(['A', 'B'], $rowsOf('VIP'));
        $this->assertSame(['C', 'D', 'E'], $rowsOf('Regular'));

        $this->assertSame(
            [1, 2, 3, 4],
            Seat::where('row_label', 'C')->orderBy('seat_number')->pluck('seat_number')->all()
        );
    }

    public function test_section_names_must_be_unique_within_a_venue(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson('/api/organizer/venues', $this->validVenue([
            'sections' => [
                ['name' => 'VIP', 'rows' => 1, 'seats_per_row' => 5],
                ['name' => 'vip', 'rows' => 1, 'seats_per_row' => 5],
            ],
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('venues', 0);
    }

    public function test_a_venue_cannot_have_more_than_26_rows(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson('/api/organizer/venues', $this->validVenue([
            'sections' => [
                ['name' => 'VIP', 'rows' => 20, 'seats_per_row' => 5],
                ['name' => 'Regular', 'rows' => 10, 'seats_per_row' => 5],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors('sections');

        $this->assertDatabaseCount('venues', 0);
    }

    public function test_at_least_one_section_is_required(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson('/api/organizer/venues', $this->validVenue(['sections' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sections');
    }

    public function test_guests_and_attendees_cannot_use_the_organizer_api(): void
    {
        $this->getJson('/api/organizer/venues')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/organizer/venues')->assertForbidden();
        $this->postJson('/api/organizer/venues', $this->validVenue())->assertForbidden();
    }

    public function test_the_index_lists_only_the_organizers_own_venues(): void
    {
        $organizer = $this->makeOrganizer();
        $this->makeVenueFor($organizer);
        $this->makeVenueFor($this->makeOrganizer());
        Sanctum::actingAs($organizer);

        $this->getJson('/api/organizer/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seats_count', 9);
    }

    public function test_show_returns_sections_and_seats(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        Sanctum::actingAs($organizer);

        $this->getJson("/api/organizer/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonCount(9, 'data.seats')
            ->assertJsonPath('data.seats.0.row_label', 'A')
            ->assertJsonPath('data.seats.0.seat_number', 1);
    }

    public function test_another_organizers_venue_is_forbidden(): void
    {
        $venue = $this->makeVenueFor($this->makeOrganizer());
        Sanctum::actingAs($this->makeOrganizer());

        $this->getJson("/api/organizer/venues/{$venue->id}")->assertForbidden();
        $this->patchJson("/api/organizer/venues/{$venue->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/organizer/venues/{$venue->id}")->assertForbidden();

        $this->assertNotSame('Hijacked', $venue->fresh()->name);
    }

    public function test_update_changes_details_but_never_the_layout(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        Sanctum::actingAs($organizer);

        $this->patchJson("/api/organizer/venues/{$venue->id}", [
            'name' => 'Renamed Hall',
            'sections' => [['name' => 'Balcony', 'rows' => 5, 'seats_per_row' => 10]],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed Hall');

        $this->assertSame('Renamed Hall', $venue->fresh()->name);
        $this->assertSame(9, Seat::where('venue_id', $venue->id)->count());
        $this->assertSame(2, $venue->sections()->count());
    }

    public function test_a_venue_without_showtimes_can_be_deleted_with_its_seats(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        Sanctum::actingAs($organizer);

        $this->deleteJson("/api/organizer/venues/{$venue->id}")->assertNoContent();

        $this->assertSame(0, Venue::count());
        $this->assertSame(0, Seat::count());
    }

    public function test_a_venue_used_by_a_showtime_cannot_be_deleted(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Showtime::factory()->create(['event_id' => $event->id, 'venue_id' => $venue->id]);
        Sanctum::actingAs($organizer);

        $this->deleteJson("/api/organizer/venues/{$venue->id}")->assertStatus(409);

        $this->assertSame(1, Venue::count());
    }
}
