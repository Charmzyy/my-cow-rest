<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Usage in routes: ->middleware('role:officer,admin') lets either role through.
// Runs after auth:sanctum, so 403 here means "signed in, but not allowed".
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Not Authorized'], 403);
        }

        return $next($request);
    }
}
