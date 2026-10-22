<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Tokens expire after this many days. The client must log in again afterwards. */
    private const TOKEN_DAYS = 30;

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password', 'phone']));

        // "role" is not mass assignable on purpose, so it is set explicitly here.
        $user->role = UserRole::from($request->validated('role') ?? UserRole::Attendee->value);
        $user->save();

        return $this->tokenResponse($request, $user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        // One generic message for "no such user" and "wrong password", so the API
        // does not reveal which emails are registered.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        return $this->tokenResponse($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Only the token used for this request is revoked, so other devices stay signed in.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user())->resolve($request),
        ]);
    }

    private function tokenResponse(Request $request, User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken(
            $request->input('device_name', 'web'),
            ['*'],
            now()->addDays(self::TOKEN_DAYS),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
            'user' => UserResource::make($user)->resolve($request),
        ], $status);
    }
}
