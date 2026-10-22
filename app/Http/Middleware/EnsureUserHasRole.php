<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users with one of the given roles.
 *
 * Usage: ->middleware(['auth:sanctum', 'role:organizer,admin'])
 *
 * Role names are validated against the UserRole enum, so a typo in a route file
 * fails loudly instead of silently locking everyone out.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        if (! in_array($user->role, $allowed, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
