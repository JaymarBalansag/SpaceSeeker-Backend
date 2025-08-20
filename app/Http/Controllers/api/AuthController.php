<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(AuthRequest $request) : JsonResponse{
        $validated = $request->validated();

        $user = User::where("email", $validated['email'])->first();

        if(!$user || !Hash::check($validated["password"], $user->password)){
            return response()->json([
                "message" => "The provided credentials are incorrect" 
            ], 401);
        }

        $token = $user->createToken($user->name."Auth-Token")->plainTextToken;

        return response()->json([
            "message" => "Login Successful",
            "token_type" => "Bearer",
            "token" => $token
        ], 200);

    }

    public function register(RegisterRequest $request): JsonResponse {
        $validated = $request->validated();

        $user = User::create([
            'first_name' => $validated["first_name"],
            'last_name' => $validated["last_name"],
            'email' => $validated["email"],
            'password' => Hash::make($validated["password"])
        ]);

        if($user){
            $token = $user->createToken($user->name."Auth-Token")->plainTextToken;
            
            return response()->json([
                "message" => "Registration Successful",
                "token_type" => "Bearer",
                "token" => $token
            ], 201);
        }
        else {
            return response()->json([
                "message" => "Something went wrong! while registration"
            ], 500);
        }
    }

    public function logout(Request $request) : JsonResponse{
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            "message" => "Logout Successful"
        ]);
    }
}
