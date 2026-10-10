<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckClientRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->tokenCan('role:client')) {
            return response()->json(['message' => 'Unauthorized. Client access required.'], 401);
        }
        return $next($request);
    }
}
