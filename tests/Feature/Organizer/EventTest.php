<?php

namespace Tests\Feature\Organizer;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Showtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\TestCase;

class EventTest extends TestCase
{
    use CreatesOrganizerFixtures, RefreshDatabase;

    private function validEvent(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Jakarta Symphony Night',
            'category' => 'orchestra',
            'description' => 'An evening of classical music.',
        ], $overrides);
    }

    public function test_an_organizer_creates_a_draft_event(): void
    {
        $organizer = $this->makeOrganizer();
        Sanctum::actingAs($organizer);

        $response = $this->postJson('/api/organizer/events', $this->validEvent())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.category', 'orchestra')
            ->assertJsonPath('data.poster_url', null);

        $this->assertStringStartsWith('jakarta-symphony-night-', $response->json('data.slug'));
        $this->assertEquals($organizer->id, Event::firstOrFail()->organizer_id);
    }

    public function test_status_cannot_be_set_through_the_request(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson('/api/organizer/events', $this->validEvent(['status' => 'published']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_the_category_must_be_valid(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $this->postJson('/api/organizer/events', $this->validEvent(['category' => 'wrestling']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_two_events_with_the_same_title_get_different_slugs(): void
    {
        Sanctum::actingAs($this->makeOrganizer());

        $first = $this->postJson('/api/organizer/events', $this->validEvent())->json('data.slug');
        $second = $this->postJson('/api/organizer/events', $this->validEvent())->json('data.slug');

        $this->assertNotSame($first, $second);
    }

    public function test_the_index_lists_only_own_events_and_filters_by_status(): void
    {
        $organizer = $this->makeOrganizer();
        Event::factory()->count(2)->create(['organizer_id' => $organizer->id]);
        Event::factory()->published()->create(['organizer_id' => $organizer->id]);
        Event::factory()->create();
        Sanctum::actingAs($organizer);

        $this->getJson('/api/organizer/events')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/organizer/events?status=published')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/organizer/events?status=nonsense')->assertUnprocessable();
    }

    public function test_show_includes_showtimes_with_their_venue(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        Sanctum::actingAs($organizer);

        $this->getJson("/api/organizer/events/{$event->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.showtimes')
            ->assertJsonStructure(['data' => ['showtimes' => [['id', 'starts_at', 'venue' => ['id', 'name', 'city']]]]]);
    }

    public function test_another_organizers_event_is_forbidden(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs($this->makeOrganizer());

        $this->getJson("/api/organizer/events/{$event->id}")->assertForbidden();
        $this->patchJson("/api/organizer/events/{$event->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->postJson("/api/organizer/events/{$event->id}/submit")->assertForbidden();
        $this->deleteJson("/api/organizer/events/{$event->id}")->assertForbidden();
    }

    public function test_a_draft_can_be_updated_and_keeps_its_slug(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id, 'slug' => 'original-slug']);
        Sanctum::actingAs($organizer);

        $this->patchJson("/api/organizer/events/{$event->id}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title')
            ->assertJsonPath('data.slug', 'original-slug');
    }

    public function test_a_published_event_cannot_be_updated(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->published()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->patchJson("/api/organizer/events/{$event->id}", ['title' => 'Sneaky edit'])->assertStatus(409);
    }

    public function test_updating_a_rejected_event_reopens_it_as_a_draft(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->rejected('Poster missing')->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->patchJson("/api/organizer/events/{$event->id}", ['description' => 'Fixed description.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_submitting_requires_an_upcoming_showtime(): void
    {
        $organizer = $this->makeOrganizer();
        $venue = $this->makeVenueFor($organizer);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$event->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('showtimes');

        // A showtime in the past does not count.
        Showtime::factory()->create(['event_id' => $event->id, 'venue_id' => $venue->id, 'starts_at' => now()->subDay()]);
        // Neither does a cancelled one.
        Showtime::factory()->cancelled()->create(['event_id' => $event->id, 'venue_id' => $venue->id, 'starts_at' => now()->addWeek()]);

        $this->postJson("/api/organizer/events/{$event->id}/submit")->assertUnprocessable();
        $this->assertSame(EventStatus::Draft, $event->fresh()->status);
    }

    public function test_an_event_with_an_upcoming_showtime_can_be_submitted_once(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        Sanctum::actingAs($organizer);

        $this->postJson("/api/organizer/events/{$event->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->postJson("/api/organizer/events/{$event->id}/submit")->assertStatus(409);
    }

    public function test_a_draft_event_can_be_deleted(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->deleteJson("/api/organizer/events/{$event->id}")->assertNoContent();

        $this->assertSame(0, Event::count());
    }

    public function test_an_event_with_orders_cannot_be_deleted(): void
    {
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $showtime = $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        Order::factory()->create(['showtime_id' => $showtime->id]);
        Sanctum::actingAs($organizer);

        $this->deleteJson("/api/organizer/events/{$event->id}")->assertStatus(409);

        $this->assertSame(1, Event::count());
    }

    public function test_a_poster_can_be_uploaded_and_replaced(): void
    {
        Storage::fake('public');
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);
        $headers = ['Accept' => 'application/json'];

        $this->post("/api/organizer/events/{$event->id}/poster", [
            'poster' => UploadedFile::fake()->image('first.jpg', 600, 800),
        ], $headers)->assertOk()->assertJsonStructure(['data' => ['poster_url']]);

        $first = $event->fresh()->poster_path;
        Storage::disk('public')->assertExists($first);

        $this->post("/api/organizer/events/{$event->id}/poster", [
            'poster' => UploadedFile::fake()->image('second.png', 600, 800),
        ], $headers)->assertOk();

        $second = $event->fresh()->poster_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_poster_must_be_a_small_image(): void
    {
        Storage::fake('public');
        $organizer = $this->makeOrganizer();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        Sanctum::actingAs($organizer);
        $headers = ['Accept' => 'application/json'];

        $this->post("/api/organizer/events/{$event->id}/poster", [
            'poster' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('poster');

        $this->post("/api/organizer/events/{$event->id}/poster", [
            'poster' => UploadedFile::fake()->image('huge.jpg')->size(3000),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('poster');
    }

    public function test_only_the_owner_can_upload_a_poster(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create();
        Sanctum::actingAs($this->makeOrganizer());

        $this->post("/api/organizer/events/{$event->id}/poster", [
            'poster' => UploadedFile::fake()->image('poster.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
