<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_an_existing_user_to_admin(): void
    {
        $user = User::factory()->create(['email' => 'aldi@example.com']);

        $this->artisan('users:make-admin', ['email' => 'Aldi@Example.com'])->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
