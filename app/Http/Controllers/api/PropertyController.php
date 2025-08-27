<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\isSubscribingRequest;

class PropertyController extends Controller
{
    public function isSubscribing(isSubscribingRequest $request){
        logger($request->all());
        $validated = $request->validated();

        $user = DB::table('users')
        ->where('email', $validated['email'])
        ->where('first_name', $validated['first_name'])
        ->where('last_name', $validated['last_name'])
        ->first();

        if($user){
            DB::table('users')
            ->where('id', $user->id)
            ->update(['role' => 'owner']);
        } else {
            return response()->json([
                "message" => "User not found"
            ], 404);
        }

        $updatedUser = DB::table('users')->where('id', $user->id)->first();

        return response()->json([
            "message" => "Property owner subscription successful",
            "role" => $updatedUser->role
        ], 200);

    }
}
