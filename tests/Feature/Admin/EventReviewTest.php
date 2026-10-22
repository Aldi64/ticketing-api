<?php

namespace Tests\Feature\Admin;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventApproved;
use App\Notifications\EventRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrganizerFixtures;
use Tests\TestCase;

class EventReviewTest extends TestCase
{
    use CreatesOrganizerFixtures, RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function pendingEvent(?User $organizer = null, array $attributes = []): Event
    {
        return Event::factory()->pendingReview()->create([
            'organizer_id' => ($organizer ?? $this->makeOrganizer())->id,
            ...$attributes,
        ]);
    }

    public function test_only_admins_can_use_the_review_api(): void
    {
        $event = $this->pendingEvent();

        $this->getJson('/api/admin/events')->assertUnauthorized();

        Sanctum::actingAs($this->makeOrganizer());
        $this->getJson('/api/admin/events')->assertForbidden();
        $this->postJson("/api/admin/events/{$event->id}/approve")->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/events')->assertForbidden();
    }

    public function test_an_organizer_cannot_approve_their_own_event(): void
    {
        $organizer = $this->makeOrganizer();
        $event = $this->pendingEvent($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/admin/events/{$event->id}/approve")->assertForbidden();

        $this->assertSame(EventStatus::PendingReview, $event->fresh()->status);
    }

    public function test_the_queue_defaults_to_events_pending_review(): void
    {
        $this->pendingEvent();
        Event::factory()->create();
        Event::factory()->published()->create();
        $this->actingAsAdmin();

        $this->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending_review');
    }

    public function test_the_queue_can_be_filtered_by_status(): void
    {
        $this->pendingEvent();
        Event::factory()->published()->count(2)->create();
        $this->actingAsAdmin();

        $this->getJson('/api/admin/events?status=published')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/events?status=nonsense')->assertUnprocessable();
    }

    public function test_the_oldest_submission_comes_first_and_shows_the_organizer(): void
    {
        $organizer = $this->makeOrganizer();
        $this->pendingEvent($organizer, ['title' => 'Newer', 'updated_at' => now()->subHour()]);
        $this->pendingEvent($organizer, ['title' => 'Older', 'updated_at' => now()->subDay()]);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Older')
            ->assertJsonPath('data.1.title', 'Newer')
            ->assertJsonPath('data.0.organizer.email', $organizer->email);
    }

    public function test_show_includes_the_organizer_showtimes_venue_and_prices(): void
    {
        $organizer = $this->makeOrganizer();
        $event = $this->pendingEvent($organizer);
        $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));
        $this->actingAsAdmin();

        $this->getJson("/api/admin/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.organizer.id', $organizer->id)
            ->assertJsonCount(1, 'data.showtimes')
            ->assertJsonCount(2, 'data.showtimes.0.prices')
            ->assertJsonStructure(['data' => ['showtimes' => [['venue' => ['name', 'city']]]]]);
    }

    public function test_approving_publishes_the_event_and_records_the_reviewer(): void
    {
        Notification::fake();
        $organizer = $this->makeOrganizer();
        $event = $this->pendingEvent($organizer);
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/admin/events/{$event->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.reviewed_by', $admin->id);

        $fresh = $event->fresh();
        $this->assertSame(EventStatus::Published, $fresh->status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNotNull($fresh->reviewed_at);

        Notification::assertSentTo(
            $organizer,
            EventApproved::class,
            fn (EventApproved $n) => $n->eventId === $event->id
        );
    }

    public function test_approving_stores_an_in_app_notification_for_the_organizer(): void
    {
        $organizer = $this->makeOrganizer();
        $event = $this->pendingEvent($organizer, ['title' => 'Symphony Night']);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/events/{$event->id}/approve")->assertOk();

        $notification = $organizer->notifications()->sole();
        $this->assertSame('event_approved', $notification->data['type']);
        $this->assertSame($event->id, $notification->data['event_id']);
        $this->assertSame('Symphony Night', $notification->data['title']);
    }

    public function test_rejecting_requires_a_reason_of_at_least_ten_characters(): void
    {
        $event = $this->pendingEvent();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/events/{$event->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'No'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(EventStatus::PendingReview, $event->fresh()->status);
    }

    public function test_rejecting_stores_the_reason_and_notifies_the_organizer(): void
    {
        Notification::fake();
        $organizer = $this->makeOrganizer();
        $event = $this->pendingEvent($organizer);
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'The poster is missing.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The poster is missing.')
            ->assertJsonPath('data.reviewed_by', $admin->id);

        $this->assertSame(EventStatus::Rejected, $event->fresh()->status);

        Notification::assertSentTo(
            $organizer,
            EventRejected::class,
            fn (EventRejected $n) => $n->reason === 'The poster is missing.'
        );
    }

    public function test_only_events_pending_review_can_be_reviewed(): void
    {
        Notification::fake();
        $organizer = $this->makeOrganizer();
        $this->actingAsAdmin();

        $events = [
            Event::factory()->create(['organizer_id' => $organizer->id]),
            Event::factory()->published()->create(['organizer_id' => $organizer->id]),
            Event::factory()->rejected()->create(['organizer_id' => $organizer->id]),
        ];

        foreach ($events as $event) {
            $before = $event->status;

            $this->postJson("/api/admin/events/{$event->id}/approve")
                ->assertStatus(409)
                ->assertJsonPath('current_status', $before->value);

            $this->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'A perfectly good reason.'])
                ->assertStatus(409);

            $this->assertSame($before, $event->fresh()->status);
        }

        Notification::assertNothingSent();
    }

    public function test_a_second_decision_on_the_same_event_is_refused(): void
    {
        Notification::fake();
        $event = $this->pendingEvent();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/events/{$event->id}/approve")->assertOk();
        $this->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'Changed my mind later.'])
            ->assertStatus(409);

        $this->assertSame(EventStatus::Published, $event->fresh()->status);
        Notification::assertCount(1);
    }

    public function test_reviewing_an_unknown_event_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/events/999999/approve')->assertNotFound();
    }

    public function test_the_full_review_cycle(): void
    {
        $organizer = $this->makeOrganizer();
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $this->makeShowtimeFor($event, $this->makeVenueFor($organizer));

        // Organizer submits, admin rejects.
        Sanctum::actingAs($organizer);
        $this->postJson("/api/organizer/events/{$event->id}/submit")->assertOk();

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'Please add a better description.'])
            ->assertOk();

        // Organizer sees the reason, fixes the event (back to draft) and resubmits.
        Sanctum::actingAs($organizer);
        $this->getJson("/api/organizer/events/{$event->id}")
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Please add a better description.');

        $this->patchJson("/api/organizer/events/{$event->id}", ['description' => 'A much better description.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->postJson("/api/organizer/events/{$event->id}/submit")->assertOk();

        // Admin approves.
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/events/{$event->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame(2, $organizer->notifications()->count());
    }
}
