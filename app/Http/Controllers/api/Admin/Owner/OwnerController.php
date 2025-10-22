<?php

namespace App\Http\Controllers\Api\Admin\Owner;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class OwnerController extends Controller
{
    public function getAllOwner() {
        try {

            $owner = DB::table('owners')
            ->join("users", "users.id", "=", "owners.user_id")
            ->join('subscriptions', 'subscriptions.id', '=', 'owners.active_subscription_id')
            ->select('owners.*', 'users.first_name', 'users.last_name', 'users.email', 'subscriptions.status', 'subscriptions.created_at')
            ->get();

            if($owner->isEmpty()){
                return response()->json([
                    "message" => "No owners found"
                ], 404);
            }

            return response()->json([
                "message" => "Owners retrieved successfully",
                "data" => $owner
            ], 200);
        } catch(\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    public function getActiveOwner() {
        try {

            $owner = DB::table('owners')
            ->join("users", "users.id", "=", "owners.user_id")
            ->join('subscriptions', 'subscriptions.id', '=', 'owners.active_subscription_id')
            ->select('owners.*', 'users.first_name', 'users.last_name', 'users.email', 'subscriptions.status', 'subscriptions.created_at')
            ->where('subscriptions.status', 'active')
            ->get();

            if($owner->isEmpty()){
                return response()->json([
                    "message" => "No active owners found"
                ], 404);
            }

            return response()->json([
                "message" => "Active owners retrieved successfully",
                "data" => $owner
            ], 200);


        } catch(\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    public function getInactiveOwner(){
        try {

            $owner = DB::table('owners')
            ->join("users", "users.id", "=", "owners.user_id")
            ->join('subscriptions', 'subscriptions.id', '=', 'owners.active_subscription_id')
            ->select('owners.*', 'users.first_name', 'users.last_name', 'users.email', 'subscriptions.status', 'subscriptions.created_at')
            ->where('subscriptions.status', 'inactive')
            ->get();

            if($owner->isEmpty()){
                return response()->json([
                    "message" => "No inactive owners found"
                ], 404);
            }

            return response()->json([
                "message" => "Inactive owners retrieved successfully",
                "data" => $owner
            ], 200);
    }
        catch(\Exception $e) {
            return response()->json([
                "message" => "Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
