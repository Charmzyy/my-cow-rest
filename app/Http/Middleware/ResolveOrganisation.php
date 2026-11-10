<?php

namespace App\Http\Middleware;

use App\Models\OrganisationMember;
use App\Models\User;
use App\Support\CurrentOrganisation;
use Closure;
use Illuminate\Http\Request;

// Sets the request's workspace from the X-Organisation header (see BelongsToOrganisation).
// No header = no workspace filter, so older app builds keep seeing every farm they're on.
// Runs before route-model binding (Kernel::$middlewarePriority), so bound farms are scoped too.
class ResolveOrganisation
{
    public function __construct(private CurrentOrganisation $current)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $id = $request->header('X-Organisation');
        $user = $request->user();

        if ($id && !$user->hasRole(User::ROLE_ADMIN)
            && !OrganisationMember::where('organisation_id', $id)->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'You are not a member of this organisation.'], 403);
        }

        $this->current->set($id ?: null);
        try {
            return $next($request);
        } finally {
            $this->current->set(null);   // the app instance outlives the request in tests and queue workers
        }
    }
}
