<?php

namespace App\Http\Controllers\Api\Admin\Users;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class UserController extends Controller
{
    public function getUserDetails(int $id)
    {
        try {
            $user = DB::table("users")
                ->select(
                    "users.id",
                    "users.first_name",
                    "users.last_name",
                    "users.phone_number",
                    "users.email",
                    "users.role",
                    "users.isComplete",
                    "users.region_name",
                    "users.state_name",
                    "users.town_name",
                    "users.village_name",
                    "users.streets",
                    "users.latitude",
                    "users.longitude",
                    "users.email_verified_at",
                    "users.created_at",
                    "users.updated_at",
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url")
                )
                ->where("users.id", $id)
                ->first();

            if (!$user) {
                return response()->json([
                    "message" => "User not found"
                ], 404);
            }

            return response()->json([
                "message" => "User details retrieved successfully",
                "data" => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function getAllUsers() {
        try {
            $users = DB::table("users")
            ->select("users.*",
            DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"))
            ->get();

            if($users->count() === 0){
                return response()->json([
                    "message" => "No users found"
                ], 404);

            }

            return response()->json([
                "message" => "Users retrieved successfully",
                "data" => $users
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ]);
        }
    }
    public function getCompleteProfile(){
        try {
            $users = DB::table("users")
                ->select(
                    "users.*",
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url")
                    )
                ->where("users.isComplete", 1) // ✅ simpler and correct
                ->get();

            if ($users->isEmpty()) {
                return response()->json([
                    "message" => "No complete users found",
                ], 404);
            }

            return response()->json([
                "message" => "Complete users retrieved successfully",
                "data" => $users
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ]);
        }
    }
    public function getIncompleteProfile() {
        try {
            $users = DB::table("users")
                ->select(
                    "users.*",
                )
                ->where("users.isComplete", "=", false || 0)
                ->get();

            if ($users->isEmpty()) {
                return response()->json([
                    "message" => "No incomplete users found",
                ], 404);
            }

            return response()->json([
                "message" => "Incomplete users retrieved successfully",
                "data" => $users
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ]);
        }
    }
}
