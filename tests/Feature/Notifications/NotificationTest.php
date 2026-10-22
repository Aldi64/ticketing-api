<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\EventApproved;
use App\Notifications\EventRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
    }

    public function test_the_list_shows_only_my_notifications_newest_first_with_an_unread_count(): void
    {
        $user = User::factory()->organizer()->create();
        $user->notify(new EventApproved(1, 'Older event'));
        $user->notify(new EventRejected(2, 'Newer event', 'Needs a better poster.'));
        User::factory()->organizer()->create()->notify(new EventApproved(3, 'Someone elses event'));

        // The "data" column is plain text, so find the older one in PHP instead of with a JSON query.
        $older = $user->notifications->first(fn ($notification) => $notification->data['event_id'] === 1);
        $older->created_at = now()->subDay();
        $older->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.type', 'event_rejected')
            ->assertJsonPath('data.0.payload.title', 'Newer event')
            ->assertJsonPath('data.0.payload.reason', 'Needs a better poster.')
            ->assertJsonPath('data.1.type', 'event_approved')
            ->assertJsonPath('data.0.read_at', null);
    }

    public function test_a_notification_can_be_marked_as_read(): void
    {
        $user = User::factory()->organizer()->create();
        $user->notify(new EventApproved(1, 'Symphony Night'));
        $notification = $user->notifications()->sole();
        Sanctum::actingAs($user);

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);

        $this->getJson('/api/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_someone_elses_notification_cannot_be_marked_as_read(): void
    {
        $owner = User::factory()->organizer()->create();
        $owner->notify(new EventApproved(1, 'Symphony Night'));
        $notification = $owner->notifications()->sole();
        Sanctum::actingAs(User::factory()->organizer()->create());

        $this->postJson("/api/notifications/{$notification->id}/read")->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_everything_can_be_marked_as_read_at_once(): void
    {
        $user = User::factory()->organizer()->create();
        $other = User::factory()->organizer()->create();
        $user->notify(new EventApproved(1, 'One'));
        $user->notify(new EventApproved(2, 'Two'));
        $other->notify(new EventApproved(3, 'Three'));
        Sanctum::actingAs($user);

        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_the_approval_email_renders_with_a_link_to_the_event(): void
    {
        config(['ticketing.frontend_url' => 'https://tickets.example.com']);
        $user = User::factory()->organizer()->create(['name' => 'Aldi']);

        $mail = (new EventApproved(7, 'Symphony Night'))->toMail($user);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Symphony Night', $mail->subject);
        $this->assertStringContainsString('Hello Aldi', $html);
        $this->assertStringContainsString('https://tickets.example.com/organizer/events/7', $html);
    }

    public function test_the_rejection_email_includes_the_reason(): void
    {
        $user = User::factory()->organizer()->create();

        $mail = (new EventRejected(7, 'Symphony Night', 'The poster is missing.'))->toMail($user);

        $this->assertStringContainsString('The poster is missing.', (string) $mail->render());
    }
}
