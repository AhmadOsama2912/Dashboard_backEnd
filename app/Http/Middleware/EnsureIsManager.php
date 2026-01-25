<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // This is a second line of defense in case the route is mis-grouped.
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Adjust to match your schema: role/type/is_manager/etc.
        if (($user->role ?? null) !== 'manager') {
            return response()->json([
                'message' => 'Forbidden: manager access required.',
            ], 403);
        }

        return $next($request);
    }
}
