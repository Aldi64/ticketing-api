<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'role:admin'])
            ->get('/api/_test/admin-only', fn () => ['ok' => true]);

        Route::middleware(['auth:sanctum', 'role:organizer,admin'])
            ->get('/api/_test/staff-only', fn () => ['ok' => true]);
    }

    public function test_guests_are_rejected_with_401(): void
    {
        $this->getJson('/api/_test/admin-only')->assertUnauthorized();
    }

    public function test_admin_can_reach_admin_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/_test/admin-only')->assertOk();
    }

    public function test_organizer_cannot_reach_admin_routes(): void
    {
        Sanctum::actingAs(User::factory()->organizer()->create());

        $this->getJson('/api/_test/admin-only')->assertForbidden();
    }

    public function test_attendee_cannot_reach_admin_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/_test/admin-only')->assertForbidden();
    }

    public function test_a_route_can_allow_several_roles(): void
    {
        Sanctum::actingAs(User::factory()->organizer()->create());
        $this->getJson('/api/_test/staff-only')->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/_test/staff-only')->assertOk();
    }

    public function test_attendees_are_rejected_from_multi_role_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/_test/staff-only')
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to perform this action.');
    }
}
