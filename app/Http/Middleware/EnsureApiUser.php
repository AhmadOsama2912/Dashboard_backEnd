<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Optional: enforce active users only (if you have such field)
        if (property_exists($user, 'is_active') && (int) $user->is_active !== 1) {
            return response()->json([
                'message' => 'Account is inactive.',
            ], 403);
        }

        return $next($request);
    }
}
