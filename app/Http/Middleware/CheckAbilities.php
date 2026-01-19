<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAbilities
{
    /**
     * Handle an incoming request.
     *
     * Usage in routes:
     *   ->middleware('abilities:user:manage')
     *   ->middleware('abilities:user:manage,screen:read')
     */
    public function handle(Request $request, Closure $next, ...$abilities): Response
    {
        $user = $request->user();

        // لازم يكون عامل login (auth:sanctum أو غيره قبله)
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // لو ما في abilities محددة، كمل عادي
        if (empty($abilities)) {
            return $next($request);
        }

        // تأكد إن اليوزر عنده كل الـ abilities المطلوبة
        foreach ($abilities as $ability) {
            if (method_exists($user, 'hasAbility')) {
                if (!$user->hasAbility($ability)) {
                    return response()->json([
                        'message' => 'Forbidden. Missing ability: ' . $ability,
                    ], 403);
                }
            } else {
                // fallback بسيط لو ما في hasAbility
                $userAbilities = (array) ($user->abilities ?? []);
                if (!in_array($ability, $userAbilities, true)) {
                    return response()->json([
                        'message' => 'Forbidden. Missing ability: ' . $ability,
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
