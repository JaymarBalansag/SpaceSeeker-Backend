<?php

namespace App\Http\Controllers\Api\Admin\Users;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

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
                    "users.user_verification_status",
                    "users.user_verification_submitted_at",
                    "users.user_verified_at",
                    "users.user_verification_rejected_reason",
                    "users.created_at",
                    "users.updated_at",
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
                    DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url")
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
            DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
            DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url"))
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
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
                    DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url")
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
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
                    DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url")
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

    public function getUserVerifications(Request $request)
    {
        try {
            $status = strtolower(trim((string) $request->query('status', 'all')));
            $allowed = ['all', 'unverified', 'pending', 'verified', 'rejected'];
            if (!in_array($status, $allowed, true)) {
                $status = 'all';
            }

            $query = DB::table("users")
                ->select(
                    "users.id",
                    "users.first_name",
                    "users.last_name",
                    "users.email",
                    "users.phone_number",
                    "users.role",
                    "users.isComplete",
                    "users.user_verification_status",
                    "users.user_verification_submitted_at",
                    "users.user_verified_at",
                    "users.user_verification_rejected_reason",
                    "users.created_at",
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
                    DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url")
                );

            if ($status !== 'all') {
                $query->where("users.user_verification_status", $status);
            }

            $users = $query->orderByDesc("users.created_at")->get();

            return response()->json([
                "message" => "User verifications retrieved successfully",
                "data" => $users,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function getUserVerificationDetails(int $id)
    {
        try {
            $user = DB::table("users")
                ->leftJoin("users as verifier", "verifier.id", "=", "users.user_verified_by_admin_id")
                ->select(
                    "users.id",
                    "users.first_name",
                    "users.last_name",
                    "users.email",
                    "users.phone_number",
                    "users.role",
                    "users.isComplete",
                    "users.user_verification_status",
                    "users.user_verification_submitted_at",
                    "users.user_verified_at",
                    "users.user_verification_rejected_reason",
                    "users.region_name",
                    "users.state_name",
                    "users.town_name",
                    "users.village_name",
                    "users.streets",
                    "users.latitude",
                    "users.longitude",
                    "users.created_at",
                    "users.updated_at",
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img_url"),
                    DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url"),
                    DB::raw("CASE WHEN verifier.id IS NOT NULL THEN CONCAT(verifier.first_name, ' ', verifier.last_name) ELSE NULL END as verified_by_admin_name")
                )
                ->where("users.id", $id)
                ->first();

            if (!$user) {
                return response()->json([
                    "message" => "User not found"
                ], 404);
            }

            return response()->json([
                "message" => "User verification details retrieved successfully",
                "data" => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function updateUserVerification(Request $request, int $id)
    {
        try {
            $validated = $request->validate([
                "status" => "required|in:verified,rejected",
                "reason" => "nullable|string|max:1000",
            ]);

            $user = DB::table("users")
                ->select("id", "user_valid_govt_id_path")
                ->where("id", $id)
                ->first();

            if (!$user) {
                return response()->json([
                    "message" => "User not found"
                ], 404);
            }

            if (!$user->user_valid_govt_id_path) {
                return response()->json([
                    "message" => "User has not submitted a verification document."
                ], 422);
            }

            if ($validated["status"] === "rejected" && empty(trim((string) ($validated["reason"] ?? "")))) {
                return response()->json([
                    "message" => "Rejection reason is required."
                ], 422);
            }

            $update = [
                "user_verification_status" => $validated["status"],
                "updated_at" => now(),
            ];

            if ($validated["status"] === "verified") {
                $update["user_verified_at"] = now();
                $update["user_verification_rejected_reason"] = null;
                $update["user_verified_by_admin_id"] = auth()->id();
            } else {
                $update["user_verified_at"] = null;
                $update["user_verification_rejected_reason"] = trim((string) $validated["reason"]);
                $update["user_verified_by_admin_id"] = null;
            }

            DB::table("users")
                ->where("id", $id)
                ->update($update);

            $reviewStatus = $validated["status"] === "verified" ? "approved" : "rejected";
            $reviewedBy = "Admin";
            if (auth()->user()) {
                $reviewedBy = trim((string) (auth()->user()->first_name . " " . auth()->user()->last_name)) ?: "Admin";
            }

            $payload = [
                "event_type" => "user_verification_status_update",
                "tab" => "system",
                "title" => "Identity verification update",
                "message" => $reviewStatus === "approved"
                    ? "Your identity verification has been approved."
                    : "Your identity verification was rejected. Reason: " . trim((string) $validated["reason"]),
                "review_status" => $reviewStatus,
                "reason" => $reviewStatus === "rejected" ? trim((string) $validated["reason"]) : null,
                "reviewed_by" => $reviewedBy,
            ];

            try {
                app(NotificationService::class)->createForUser((int) $user->id, $payload);
            } catch (\Exception $notifyException) {
                Log::warning("Failed to send user verification notification", [
                    "user_id" => $user->id,
                    "status" => $reviewStatus,
                    "error" => $notifyException->getMessage(),
                ]);
            }

            return response()->json([
                "message" => "User verification status updated successfully"
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
