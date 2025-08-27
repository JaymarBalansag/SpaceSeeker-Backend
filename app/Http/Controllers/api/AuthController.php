<?php

namespace App\Http\Controllers\api;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\AuthRequest;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\RegisterRequest;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{
    public function login(AuthRequest $request)
    {
        $validated = $request->validated();
        
        $user = User::where("email", $validated['email'])->first();

        if(!$user || !Hash::check($validated["password"], $user->password)){
             return response()->json([
                 "message" => "The provided credentials are incorrect" 
             ], 401);
        }

        // Short-lived access token (15 mins)
        $accessToken = $user->createToken('access-token', ['*'], Carbon::now()->addMinutes(15))->plainTextToken;

        // Long-lived refresh token (7 days)
        $refreshToken = $user->createToken('refresh-token', ['*'], Carbon::now()->addDays(2))->plainTextToken;

        $cookie = Cookie::make(
            'refresh_token',
            $refreshToken,
            60 * 24 * 2, 
            "/",
            null,
            false, // secure
            true, // httpOnly
            false,
            'Lax'
        );

        

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 900, // 15 mins
            'user' => $user
        ])->cookie($cookie);
    }

    public function refresh(Request $request)
    {
        $refreshToken = $request->cookie('refresh_token');

        if (!$refreshToken) {
            return response()->json(['message' => 'No refresh token'], 401);
        }

        $user = User::whereHas('tokens', fn($q) => $q->where('token', hash('sha256', explode('|', $refreshToken)[1])))->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $accessToken = $user->createToken('access-token', ['*'], Carbon::now()->addMinutes(15))->plainTextToken;

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ]);
    }


    public function register(RegisterRequest $request)
    {
        // Validate input
        $validated = $request->validated();
        // Create user
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Create token (Sanctum)
        if($user){
            // Short-lived access token (15 mins)
            $accessToken = $user->createToken('access-token', ['*'], Carbon::now()->addMinutes(15))->plainTextToken;

            // Long-lived refresh token (7 days)
            $refreshToken = $user->createToken('refresh-token', ['*'], Carbon::now()->addDays(2))->plainTextToken;

            $cookie = Cookie::make(
                'refresh_token',
                $refreshToken,
                60 * 24 * 2, 
                "/",
                null,
                false, // secure
                true, // httpOnly
                false,
                'Lax'
            );
            return response()->json([
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 900, // 15 mins
                'user' => $user
            ])->cookie($cookie);
        }
        else {
            return response()->json([
                 "message" => "Something went wrong! while registration"
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out'])
            ->cookie(Cookie::forget('refresh_token'));
    }
}
