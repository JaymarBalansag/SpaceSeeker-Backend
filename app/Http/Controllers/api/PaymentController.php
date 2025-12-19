<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    //
    public function confirm(Request $request)
    {
        $user = Auth::user();

        // 1. Prevent double subscription
        if ($user->role === 'owner') {
            return response()->json([
                'message' => 'You are already an owner'
            ], 403);
        }

        // 2. Validate request
        $request->validate([
            'plan' => 'required|in:Monthly,Annual',
            'payment_method' => 'required'
        ]);

        // 3. Determine plan details (NEVER trust frontend)
        if ($request->plan === 'Monthly') {
            $amount = 50;
            $billing = 'monthly';
            $listingLimit = 5;
            $endDate = Carbon::now()->addMonth();
        } else {
            $amount = 500;
            $billing = 'annual';
            $listingLimit = 15;
            $endDate = Carbon::now()->addYear();
        }

        DB::beginTransaction();

        try {
            // 4. Create subscription (simulation = active)
            $subscriptionId = DB::table('subscriptions')->insertGetId([
                'user_id' => $user->id,
                'plan_name' => $request->plan,
                'amount' => $amount,
                'billing_cycle' => $billing,
                'listing_limit' => $listingLimit,
                'start_date' => Carbon::now(),
                'end_date' => $endDate,
                'payment_provider' => 'simulation',
                'payment_method' => $request->payment_method,
                'status' => 'active',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // 5. Create owner
            $ownerId = DB::table('owners')->insertGetId([
                'user_id' => $user->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // 6. Attach owner to subscription
            DB::table('subscriptions')
                ->where('id', $subscriptionId)
                ->update([
                    'owner_id' => $ownerId,
                    'updated_at' => Carbon::now(),
                ]);

            // 7. Update user role
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'role' => 'owner',
                    'updated_at' => Carbon::now(),
                ]);

            DB::commit();

            // 8. Fetch updated user
            $updatedUser = DB::table('users')->where('id', $user->id)->first();

            return response()->json([
                'message' => 'Subscription activated',
                'user' => $updatedUser,
                'subscription_id' => $subscriptionId
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
