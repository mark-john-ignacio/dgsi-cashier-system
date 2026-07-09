<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        $user = $request->user();
        abort_unless($user && ($user->role === $role || $user->isAdmin()), 403);

        return $next($request);
    }
}
