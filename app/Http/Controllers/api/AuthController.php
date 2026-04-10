<?php

namespace App\Http\Controllers\api;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\AuthRequest;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
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
                 "message" => "The provided credentials are incorrect",
                 "error_type" => "Credential",
             ], 401);
        }

        $tokens = $this->issueTokens($user);

        return response()->json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => 900, // 15 mins
            'user' => $this->loadAuthUserPayload($user->id),
        ])->cookie($tokens['cookie']);
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
        try {
            // Validate input
            $validated = $request->validated();
            
            // Create user
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $user->sendEmailVerificationNotification();

            $userId = $user->id;

            return response()->json([
                'message' => 'User registered successfully',
                "userID" => $userId
            ], 200);
            
        } catch (\Throwable $th) {
            return response()->json(["message" => "Something went wrong! while registration" . $th->getMessage()], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()
        ->tokens()
        ->where('id', $request->user()->currentAccessToken()->id)
        ->delete();

        return response()->json(['message' => 'Logged out'])
            ->cookie(Cookie::forget('refresh_token'));
    }

    private function issueTokens(User $user): array
    {
        $accessToken = $user->createToken('access-token', ['*'], Carbon::now()->addMinutes(15))->plainTextToken;
        $refreshToken = $user->createToken('refresh-token', ['*'], Carbon::now()->addDays(2))->plainTextToken;

        $cookie = Cookie::make(
            'refresh_token',
            $refreshToken,
            60 * 24 * 2,
            '/',
            null,
            false,
            true,
            false,
            'Lax'
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'cookie' => $cookie,
        ];
    }

    private function loadAuthUserPayload(int $userId)
    {
        return DB::table("users")
            ->leftJoin("owners", "owners.user_id", "=", "users.id")
            ->select(
                "users.*",
                "owners.owner_verification_status",
                "owners.owner_verified_at",
                DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
                DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url")
            )
            ->where("users.id", "=", $userId)
            ->first();
    }
}
