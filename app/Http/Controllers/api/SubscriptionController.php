<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionLifecycleService $subscriptionLifecycleService)
    {
    }

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

            $activeSubscription = DB::table("subscriptions")
                ->where("owner_id", $owner->id)
                ->where("status", "active")
                ->whereDate("end_date", ">=", now()->toDateString())
                ->orderByDesc("end_date")
                ->orderByDesc("id")
                ->first();

            if (!$activeSubscription) {
                return response()->json([
                    'message' => 'No active subscription found.',
                    'listing_limit' => 0,
                    'current_listings' => 0,
                ], 200);
            }

            $propertyCount = DB::table("properties")
                ->where("owner_id", $owner->id)
                ->count();

            return response()->json([
                "listing_limit" => $activeSubscription->listing_limit,
                "current_listings" => $propertyCount
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getSubscriptionStatus(int $subscriptionId) {
        try {
            $ownerId = DB::table('owners')->where('user_id', Auth::id())->value('id');
            $subscription = DB::table("subscriptions")
                ->where("id", $subscriptionId)
                ->where(function ($query) use ($ownerId) {
                    $query->where('user_id', Auth::id());
                    if ($ownerId) {
                        $query->orWhere('owner_id', $ownerId);
                    }
                })
                ->first();

            if (!$subscription) {
                return response()->json([
                    'message' => 'Subscription not found for this user'
                ], 404);
            }

            return response()->json([
                "message" => "Subscription Status retrieved Successfuly",
                "status" => $subscription->status,
                "subscription_id" => $subscription->id,
                "end_date" => $subscription->end_date,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getOwnerSubscriptionStatus()
    {
        try {
            $snapshot = $this->subscriptionLifecycleService->getOwnerSubscriptionSnapshotByUserId(Auth::id());

            return response()->json([
                'message' => 'Owner subscription status retrieved successfully',
                'data' => $snapshot,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getOwnerSubscriptionHistory()
    {
        try {
            $userId = Auth::id();
            $ownerId = DB::table('owners')->where('user_id', $userId)->value('id');

            if (!$ownerId) {
                return response()->json([
                    'message' => 'Owner not found',
                    'data' => [],
                ], 200);
            }

            $history = DB::table('subscription_history')
                ->where('owner_id', $ownerId)
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'message' => 'Subscription history retrieved successfully',
                'data' => $history,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

}
