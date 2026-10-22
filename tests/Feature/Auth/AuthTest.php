<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aldi Putra',
            'email' => 'aldi@example.com',
            'password' => 'secret-pass-123',
        ], $overrides);
    }

    public function test_register_creates_an_attendee_by_default_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegistration());

        $response->assertCreated()
            ->assertJsonPath('user.email', 'aldi@example.com')
            ->assertJsonPath('user.role', 'attendee')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'phone', 'role']])
            ->assertJsonMissingPath('user.password');

        $this->assertSame(UserRole::Attendee, User::firstWhere('email', 'aldi@example.com')->role);
    }

    public function test_register_can_choose_the_organizer_role(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration(['role' => 'organizer']))
            ->assertCreated()
            ->assertJsonPath('user.role', 'organizer');
    }

    public function test_register_cannot_create_an_admin(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration(['role' => 'admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_rejects_a_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'aldi@example.com']);

        $this->postJson('/api/auth/register', $this->validRegistration(['email' => 'ALDI@Example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_register_requires_a_password_of_at_least_eight_characters(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration(['password' => 'short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration())->assertCreated();

        $stored = User::firstWhere('email', 'aldi@example.com')->getRawOriginal('password');

        $this->assertNotSame('secret-pass-123', $stored);
        $this->assertTrue(password_verify('secret-pass-123', $stored));
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        User::factory()->create(['email' => 'aldi@example.com', 'password' => 'secret-pass-123']);

        $this->postJson('/api/auth/login', ['email' => 'Aldi@Example.com', 'password' => 'secret-pass-123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'aldi@example.com')
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user']);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'aldi@example.com', 'password' => 'secret-pass-123']);

        $this->postJson('/api/auth/login', ['email' => 'aldi@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_gives_the_same_error_for_an_unknown_email(): void
    {
        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123']);

        User::factory()->create(['email' => 'aldi@example.com', 'password' => 'secret-pass-123']);
        $wrong = $this->postJson('/api/auth/login', ['email' => 'aldi@example.com', 'password' => 'wrong-password']);

        $unknown->assertUnprocessable();
        $this->assertSame($unknown->json('errors.email'), $wrong->json('errors.email'));
    }

    public function test_issued_tokens_expire(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration())->assertCreated();

        $token = PersonalAccessToken::query()->firstOrFail();

        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->isFuture());
    }

    public function test_a_bearer_token_authenticates_requests(): void
    {
        $token = $this->postJson('/api/auth/register', $this->validRegistration())->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'aldi@example.com');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_current_user(): void
    {
        $user = User::factory()->organizer()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'organizer');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-123']);
        $credentials = ['email' => $user->email, 'password' => 'secret-pass-123'];

        $phone = $this->postJson('/api/auth/login', $credentials + ['device_name' => 'phone'])->json('token');
        $this->postJson('/api/auth/login', $credentials + ['device_name' => 'laptop'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($phone)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'laptop']);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'phone']);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
