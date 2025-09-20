<?php

// app/Http/Middleware/ScreenTokenAuth.php
namespace App\Http\Middleware;

use App\Models\Screen;
use Closure;
use Illuminate\Http\Request;

class ScreenTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('X-Screen-Token');
        if (!$token) return response()->json(['message'=>'Missing screen token'], 401);

        $screen = Screen::where('api_token', $token)->first();
        if (!$screen) return response()->json(['message'=>'Invalid screen token'], 401);

        $request->attributes->set('screen', $screen);
        return $next($request);
    }
}
