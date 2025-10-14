<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    //
    public function confirm(Request $request)
    {
        try {
            // dd($request->all());
            $user = Auth::user();

            // Step 1: Check role
            if ($user->role === 'owner') {
                return response()->json(['message' => 'Already an owner.'], 400);
            }

            // Step 2: Promote user to owner if not yet
            $ownerId = DB::table('owners')->insertGetId([
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Step 3: Determine plan details
            $plan = strtolower($request->plan); // monthly or annual
            $start = now();
            $due = $plan === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth();
            $price = $plan === 'annual' ? 5000 : 500; // Example prices
            $listingLimit = $plan === 'annual' ? 15 : 5; // Example limits

            // Step 4: Create subscription record
            $subId = DB::table('subscriptions')->insertGetId([
                'owner_id' => $ownerId,
                'plan_name' => $plan,
                'amount' => $price,
                'billing_cycle' => $plan,
                'start_date' => $start,
                'end_date' => $due,
                'status' => 'active',
                'listing_limit' => $listingLimit,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Step 5: Update owner with active subscription ID
            DB::table('owners')->where('id', $ownerId)->update([
                'active_subscription_id' => $subId
            ]);

            // Step 6: Update user role
            DB::table('users')->where('id', $user->id)->update(['role' => 'owner']);

            return response()->json([
                'message' => 'Payment confirmed. You are now an Owner!',
                'plan' => $plan,
                'subscription_id' => $subId
            ]);
        } catch (\Exception $e) {
            //throw $th;
            return response()->json(['error' => 'An error occurred while processing your request.' . $e], 500);
        }
        
    }
}
