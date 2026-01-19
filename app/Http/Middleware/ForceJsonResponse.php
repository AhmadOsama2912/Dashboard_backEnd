<?php

// app/Http/Middleware/ForceJsonResponse.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        // Make Laravel treat this as an API/JSON request
        $request->headers->set('Accept', 'application/json');
        return $next($request);
    }
}
