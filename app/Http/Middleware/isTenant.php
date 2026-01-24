<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class isTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Assuming your User model has a 'role' column
        if ($request->user() && $request->user()->role === 'tenants') {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Tenant access only.'
        ], 403);
    }
}
