<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAbilities
{
    public function handle(Request $request, Closure $next, ...$abilities): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Manager => allow everything (bypass ability checks)
        if (($user->role ?? null) === 'manager' || (method_exists($user, 'isManager') && $user->isManager())) {
            return $next($request);
        }

        // If no abilities required, allow
        if (empty($abilities)) {
            return $next($request);
        }

        // If using Sanctum token, enforce token abilities
        $token = $user->currentAccessToken();

        foreach ($abilities as $ability) {
            $ability = strtolower(trim((string) $ability));

            if ($token) {
                // token must include the ability (or wildcard)
                if (!$token->can($ability) && !$token->can('*')) {
                    return response()->json([
                        'message' => 'Forbidden. Missing ability: ' . $ability,
                    ], 403);
                }
            } else {
                // fallback for non-token auth
                if (!method_exists($user, 'hasAbility') || !$user->hasAbility($ability)) {
                    return response()->json([
                        'message' => 'Forbidden. Missing ability: ' . $ability,
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
