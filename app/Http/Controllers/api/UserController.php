<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\ProfileCompletionRequest;

class UserController extends Controller
{
    public function completeProfile(ProfileCompletionRequest $request)
    {
        try {
            $validated = $request->validated();

            if (!$request->hasFile('user_img')) {
                return response()->json([
                    "message" => "No image provided"
                ]);
            }

            $path = $request->file('user_img')->store('profile_images', 'public');


            $user = $request->user();
            DB::table('users')
            ->where('id', $user->id)
            ->update([
                'phone_number' => $validated['phone_number'] ?? $user->phone_number,
                'streets'      => $validated['streets'] ?? $user->streets,
                'region_name'    => $validated['region_name'] ?? $user->region_id,
                'state_name'  => $validated['state_name'] ?? $user->province_id,
                'town_name'   => $validated['town_name'] ?? $user->muncity_id,
                'village_name'  => $validated['village_name'] ?? $user->barangay_id,
                'latitude'     => $validated['latitude'] ?? $user->latitude,
                'longitude'    => $validated['longitude'] ?? $user->longitude,
                'user_img'     => $path,
                'iscomplete'   => true,
            ]);

            $userImage = DB::table("users")
            ->select(
                DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"))
            ->where("users.id", "=", $user->id)
            ->first();



            return response()->json([
                'message' => 'Profile completed successfully',
                'user'    => $user->fresh(), // get latest data after update
                'userImage' => $userImage
            ]);


        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error completing user: ' . $e->getMessage(),
            ], 500);
        }

        
    }

    public function getUser(){
        try {
            
            $userid = Auth::id();

            $user = DB::table("users")
            ->select("users.*", 
            DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"))
            ->where("users.id", "=", $userid)
            ->get();

            return response()->json([
                "message" => "User found",
                "userid" => $userid,
                "user" => $user 
            ],200);

        } catch (\Exception $e) {
           return response()->json([
                'message' => 'Error getting user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getUserID(){
        try {
            
            $userid = Auth::id();

            return response()->json([
                "message" => "User ID retrieved successfully",
                "userid" => $userid
            ],200);

        } catch (\Exception $e) {
           return response()->json([
                'message' => 'Error getting user ID: ' . $e->getMessage(),
            ], 500);
        }
    }

}
