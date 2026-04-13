<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Client;


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
            $amount = 200;
            $billing = 'monthly';
            $listingLimit = 2;
            $endDate = Carbon::now()->addMonth();
        } else {
            $amount = 2000;
            $billing = 'annual';
            $listingLimit = 5;
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

    public function subscribe(Request $request){
        try {
            
            $request->validate(['plan' => 'required|in:Monthly,Annual']);

            $amount = $request->plan === 'Monthly' ? 50000 : 500000; // in centavos



            $subscriptionId = DB::table("subscriptions")
            ->insertGetId([
                "user_id" => Auth::id(),
                'plan_name' => $request->plan,
                'payment_method' => "qr_ph",
                'amount' => $amount,
                'status' => 'pending',
            ]);


            // 2️⃣ Call PayMongo API to create QR source
            $client = new Client();
            $response = $client->post('https://api.paymongo.com/v1/sources', [
                'auth' => [env('PAYMONGO_SECRET_KEY'), ''],
                'json' => [
                    'data' => [
                        'attributes' => [
                            'type' => 'qr_ph', // use 'paymaya' if needed
                            'amount' => $amount,
                            'currency' => 'PHP',
                            'redirect' => [
                                'success' => config('app.url') . '/payment/success',
                                'failed'  => config('app.url') . '/payment/failed',
                            ],
                            'metadata' => [
                                'subscription_id' => $subscriptionId,
                                'user_id' => Auth::id()
                            ]
                        ]
                    ]
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $qrUrl = $data['data']['attributes']['redirect']['checkout_url'];
            $sourceId = $data['data']['id'];

            // 3️⃣ Save PayMongo source ID
            $subscription = DB::table("subscriptions")
            ->where("id", "=", $subscriptionId)
            ->update([
                "payment_source_id" => $sourceId
            ]);

            return response()->json([
                'qr_url' => $qrUrl,
                'subscription_id' => $subscriptionId
            ]);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function webhook(Request $request){
        $payload = $request->all();

        // Check if event is a QR payment ready to be charged
        if (isset($payload['type']) && $payload['type'] === 'source.chargeable') {

            $subscriptionId = $payload['data']['attributes']['metadata']['subscription_id'];

            // Get the subscription record
            $subscription = DB::table("subscriptions")
                ->select("id", "plan_name")
                ->where("id", $subscriptionId)
                ->first();

            if ($subscription) {
                $start_date = now();
                $end_date = $subscription->plan_name === 'Monthly' ? now()->addMonth() : now()->addYear();

                // Update subscription status and dates
                DB::table("subscriptions")
                    ->where("id", $subscription->id)
                    ->update([
                        "status" => "active",
                        "start_date" => $start_date,
                        "end_date" => $end_date
                    ]);
            }
        }

        return response()->json(['received' => true]);
    }


    
}
