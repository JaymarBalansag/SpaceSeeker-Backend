<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function getPropertyLimit() {
        try {
            $userID = Auth::id();

            $owner = DB::table("owners")
                ->select("id")
                ->where("user_id", $userID)
                ->first();

            if (!$owner) {
                return response()->json([
                    'message' => 'Owner not found for this user.'
                ], 404);
            }

            $listingLimit = DB::table("subscriptions")
                ->where("owner_id", $owner->id)
                ->value('listing_limit');

            $propertyCount = DB::table("properties")
                ->where("owner_id", $owner->id)
                ->count();

            return response()->json([
                "listing_limit" => $listingLimit,
                "current_listings" => $propertyCount
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

}
