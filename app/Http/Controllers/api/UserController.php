<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ProfileCompletionRequest;
use App\Http\Requests\updateUserProfileRequest;
use App\Http\Requests\UpdateUserLocationRequest;
use App\Http\Requests\PasswordVerificationRequest;

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

    public function updateProfile(UpdateUserProfileRequest $request){
        try {
            $validated = $request->validated();
            $user = Auth::user();

            /** 🔹 Get old image BEFORE update */
            $oldImage = DB::table('users')
                ->where('id', $user->id)
                ->value('user_img');

            $updateData = [
                'first_name'   => $validated['first_name'],
                'last_name'    => $validated['last_name'],
                'phone_number' => $validated['phone_number'] ?? null,
                'updated_at'   => now(),
            ];

            /** 🔹 Handle Image Replacement */
            if ($request->hasFile('user_img')) {

                // Delete old image if exists
                if ($oldImage && Storage::disk('public')->exists($oldImage)) {
                    Storage::disk('public')->delete($oldImage);
                }

                // Store new image
                $path = $request->file('user_img')
                    ->store('profile_images', 'public');

                $updateData['user_img'] = $path;
            }

            DB::table('users')
                ->where('id', $user->id)
                ->update($updateData);

            /** 🔹 Return updated user */
            $updatedUser = DB::table('users')
                ->select(
                    'id',
                    'first_name',
                    'last_name',
                    DB::raw("
                        CASE 
                            WHEN user_img IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', user_img)
                            ELSE NULL 
                        END AS user_img_url
                    ")
                )
                ->where('id', $user->id)
                ->first();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user'    => $updatedUser
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating profile',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function updateLocation(UpdateUserLocationRequest $request){
        try {
            $validated = $request->validated();
            $user = Auth::user();

            $updateData = [
                "streets" => $validated["streets"],
                "region_name" => $validated["region_name"],
                "state_name" => $validated["state_name"],
                "town_name" => $validated["town_name"],
                "village_name" => $validated["village_name"],
                "latitude" => $validated["latitude"],
                "longitude" => $validated["longitude"],
            ];

            DB::table('users')
                ->where('id', $user->id)
                ->update($updateData);

            return response()->json([
                "message" => "Location Update Successfully"
            ]);

        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getUser(){
        try {
            
            $userid = Auth::id();

            $user = DB::table("users")
            ->leftJoin("owners", "owners.user_id", "=", "users.id")
            ->select(
                "users.*",
                "owners.owner_verification_status",
                "owners.owner_verified_at",
                DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url")
            )
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

    public function verifyPassword(PasswordVerificationRequest $request) {
        try {
            $validated = $request->validated();
            //code...
            $user = Auth::user();

            if(Hash::check($validated['current_password'], $user->password)){
                return response()->json([
                    'message' => 'Password verified successfully',
                    'verified' => true
                ], 200);
            } else {
                return response()->json ([
                    'message' => 'Password verification failed',
                    'verified' => false
                ], 401);
            }

        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'message' => 'Error verifying password: ' . $th->getMessage(),
            ], 500);
        }
    }

    public function changePassword(ChangePasswordRequest $request){
        try {
            $validated = $request->validated();

            if($validated['new_password'] !== $validated['confirm_password']){
                return response()->json([
                    'message' => 'New password and confirm password do not match',
                ], 400);
            }

            //code...
            $user = Auth::user();

            DB::table('users')
            ->where('id', $user->id)
            ->update([
                'password' => Hash::make($validated['new_password']),
                'updated_at' => Carbon::now(),
            ]);

            return response()->json([
                'message' => 'Password changed successfully',
            ], 200);

        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'message' => 'Error changing password: ' . $th->getMessage(),
            ], 500);
        }
    }

    

}
