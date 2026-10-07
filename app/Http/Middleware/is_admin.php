<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class is_admin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
      
        // Signed in but the wrong role -> 403 Forbidden (401 means "not signed in")
        if (!Auth::check() || Auth::user()->role != 1) {
            return response()->json(['message' => 'Not Authorized'], 403);
        }
        return $next($request);
    }
}
