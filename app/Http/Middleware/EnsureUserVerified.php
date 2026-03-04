<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (strtolower((string) $user->user_verification_status) !== 'verified') {
            return response()->json([
                'message' => 'User verification is required for this action.',
                'code' => 'USER_NOT_VERIFIED',
                'status' => $user->user_verification_status ?? 'unverified',
            ], 403);
        }

        return $next($request);
    }
}

