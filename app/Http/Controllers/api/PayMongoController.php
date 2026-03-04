<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PayMongoController extends Controller
{
    private $secretKey;

    public function __construct() {
        $this->secretKey = config('services.paymongo.secret_key');
    }

    /**
     * PayMongo HTTP client.
     *
     * Localhost note:
     * - In local environment only, we allow TLS bypass for dev machines that
     *   do not have proper CA/curl certificates configured yet.
     *
     * Production note:
     * - Keep strict TLS verification enabled (default behavior).
     * - Do not call withoutVerifying() outside local.
     */
    private function payMongoHttp()
    {
        $client = Http::withBasicAuth($this->secretKey, '');

        if (app()->environment('local')) {
            return $client->withoutVerifying();
        }

        return $client;
    }

    public function createPayment(Request $request) {
        $user = Auth::user();

        if ($user->role === 'owner') {
            return response()->json(['message' => 'You are already an owner'], 403);
        }

        if (strtolower((string) ($user->user_verification_status ?? 'unverified')) !== 'verified') {
            return response()->json([
                'message' => 'Verify your account first before applying as owner.',
                'code' => 'USER_NOT_VERIFIED',
                'status' => $user->user_verification_status ?? 'unverified',
            ], 403);
        }

        $request->validate([
            'plan' => 'required|in:Monthly,Annual',
            'paymentType' => "required|string",
            'phone' => 'required|string',
            'permit_acknowledged' => 'required|accepted',
        ]);

        // 1. Calculate Plan Details
        if ($request->plan === 'Monthly') {
            $amount = 1;
            $billing = 'monthly';
            $listingLimit = 2;
        } else {
            $amount = 2;
            $billing = 'annual';
            $listingLimit = 5;
        }

        $existingOwner = DB::table('owners')->where('user_id', $user->id)->first();

        // 2. Insert Pending Subscription into DB
        // We do this first so we have a record even if they don't pay yet
        try {
            DB::beginTransaction();

            $ownerData = [
                'paymentType' => $request->paymentType,
                'phone_number' => $request->phone,
                'status' => 'pending', // Not yet an active owner
                'permit_compliance_acknowledged' => true,
                'permit_compliance_acknowledged_at' => now(),
                'owner_verification_status' => ($existingOwner && $existingOwner->owner_verification_status === 'verified')
                    ? 'verified'
                    : 'pending',
                'owner_verified_at' => ($existingOwner && $existingOwner->owner_verification_status === 'verified')
                    ? ($existingOwner->owner_verified_at ?? now())
                    : null,
                'updated_at' => now(),
            ];

            if (!$existingOwner) {
                $ownerData['created_at'] = now();
            }

            DB::table('owners')->updateOrInsert(['user_id' => $user->id], $ownerData);

            $subscriptionId = DB::table('subscriptions')->insertGetId([
                'user_id' => $user->id,
                'plan_name' => $request->plan,
                'amount' => $amount,
                'billing_cycle' => $billing,
                'listing_limit' => $listingLimit,
                'start_date' => Carbon::now(),
                'payment_provider' => 'paymongo',
                'payment_method' => 'qrph',
                'status' => 'pending', // Key: Pending until webhook hits
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

        } catch (\Exception $e) {
            //throw $th;
            DB::rollBack();
            return response()->json(['error' => 'Failed to initiate' . $e], 500);
        } 

        
        

        // 3. PayMongo API Calls
        $intentResponse = $this->payMongoHttp()
        ->post('https://api.paymongo.com/v1/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $amount * 100,
                    'payment_method_allowed' => ['qrph'],
                    'currency' => 'PHP',
                    'description' => $subscriptionId, // Help track which ID this is
                ]
            ]
        ]);

        if (!$intentResponse->successful()) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return response()->json([
                'error' => 'Failed to create payment intent',
                'details' => $intentResponse->json(),
            ], 502);
        }

        $intent = $intentResponse->json();
        $intentId = $intent['data']['id'] ?? null;

        if (!$intentId) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return response()->json(['error' => 'Payment intent id missing'], 502);
        }

        DB::table('subscriptions')->where('id', $subscriptionId)->update([
            'payment_intent_id' => $intentId,
            'updated_at' => now(),
        ]);

        $methodResponse = $this->payMongoHttp()
        ->post('https://api.paymongo.com/v1/payment_methods', [
            'data' => [
                'attributes' => [
                    'type' => 'qrph',
                    'billing' => [
                        'name' => $user->first_name . " " . $user->last_name,
                        'email' => $user->email
                    ]
                ]
            ]
        ]);

        if (!$methodResponse->successful()) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return response()->json([
                'error' => 'Failed to create payment method',
                'details' => $methodResponse->json(),
            ], 502);
        }

        $method = $methodResponse->json();
        $paymentMethodId = $method['data']['id'] ?? null;

        if (!$paymentMethodId) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return response()->json(['error' => 'Payment method id missing'], 502);
        }

        $attachResponse = $this->payMongoHttp()
        ->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                    'return_url' => url('/payment/success')
                ]
            ]
        ]);

        if (!$attachResponse->successful()) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return response()->json([
                'error' => 'Failed to attach payment method',
                'details' => $attachResponse->json(),
            ], 502);
        }

        $attach = $attachResponse->json();

        // Use the path you found in your debug data
        $qrCode = $attach['data']['attributes']['next_action']['code']['image_url'] ?? null;

        // Fallback to the other common path just in case
        if (!$qrCode) {
            $qrCode = $attach['data']['attributes']['next_action']['render_qr_code'] ?? null;
        }

        return response()->json([
            'qr_code' => $qrCode,
            'subscription_id' => $subscriptionId,
            'full_response' => $attach
        ]);
    }

    public function handleWebhook(Request $request) {
        // 1. Initial Logging
        Log::info('PayMongo Webhook Received:', $request->all());

        $payload = $request->all();
        
        // Safety check for payload structure
        if (!isset($payload['data']['attributes']['type'])) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $type = $payload['data']['attributes']['type'];
        $eventData = $payload['data']['attributes']['data']['attributes'] ?? null;

        if (!$eventData) {
            return response()->json(['message' => 'No event data found'], 400);
        }

        // 2. Find subscription primarily by payment_intent_id
        $targetSubId = (int) ($eventData['description'] ?? 0); // fallback only
        $paymentIntentCandidates = [
            $eventData['payment_intent_id'] ?? null,
            data_get($eventData, 'payment_intent.id'),
            data_get($payload, 'data.attributes.data.attributes.payment_intent_id'),
            data_get($payload, 'data.attributes.data.attributes.payment_intent.id'),
            data_get($payload, 'data.attributes.data.id'),
        ];

        $paymentIntentId = null;
        foreach ($paymentIntentCandidates as $candidate) {
            if (is_string($candidate) && str_starts_with($candidate, 'pi_')) {
                $paymentIntentId = $candidate;
                break;
            }
        }

        // --- CASE 1: PAYMENT SUCCESS ---
        if ($type === 'payment.paid') {
            $subQuery = DB::table('subscriptions');

            if ($paymentIntentId) {
                $subQuery->where('payment_intent_id', $paymentIntentId);
            } elseif ($targetSubId) {
                $subQuery->where('id', $targetSubId);
            }

            $sub = $subQuery->first();

            if ($sub) {
                DB::beginTransaction();
                try {
                    // Idempotency: webhook retries should not re-run activation
                    if ($sub->status === 'active') {
                        DB::commit();
                        return response()->json(['message' => 'Already processed'], 200);
                    }

                    if ($sub->status !== 'pending') {
                        DB::commit();
                        return response()->json(['message' => 'No pending subscription to activate'], 200);
                    }

                    $user = User::find($sub->user_id);
                    if (!$user) {
                        DB::rollBack();
                        Log::warning("Webhook user not found for subscription {$sub->id}");
                        return response()->json(['message' => 'User not found'], 200);
                    }

                    // Subscription End
                    $endDate = $sub->billing_cycle === 'monthly' ? now()->addMonth() : now()->addYear();

                    // Update Subscription to Active
                    DB::table('subscriptions')->where('id', $sub->id)->update([
                        'status' => 'active',
                        'end_date' => $endDate,
                        'updated_at' => now()
                    ]);

                    // Create or Get Owner Record (using updateOrInsert to avoid duplicates)
                    $owner = DB::table('owners')->where('user_id', $user->id)->first();
                    if ($owner) {
                        DB::table('owners')
                            ->where('user_id', $user->id)
                            ->update([
                                'status' => 'active',
                                'owner_verification_status' => $owner->owner_verification_status === 'verified' ? 'verified' : 'pending',
                                'owner_verified_at' => $owner->owner_verification_status === 'verified' ? $owner->owner_verified_at : null,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('owners')->insert([
                            'user_id' => $user->id,
                            'status' => 'active',
                            'owner_verification_status' => 'pending',
                            'owner_verified_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $owner = DB::table('owners')->where('user_id', $user->id)->first();

                    // Link Owner to Subscription
                    DB::table('subscriptions')->where('id', $sub->id)->update(['owner_id' => $owner->id]);

                    // Update User Role to Owner
                    $user->update(['role' => 'owner']);

                    DB::commit();
                    Log::info("User {$user->email} successfully upgraded for Sub #{$sub->id}");
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Webhook DB Error: ' . $e->getMessage());
                    return response()->json(['error' => 'Processing failed'], 500);
                }
            } else {
                Log::warning('payment.paid received but no matching subscription found', [
                    'payment_intent_id' => $paymentIntentId,
                    'fallback_subscription_id' => $targetSubId
                ]);
            }
        }

        // --- CASE 2: PAYMENT FAILED ---
        elseif ($type === 'payment.failed') {
            Log::warning("Payment Failed for Sub ID: {$targetSubId}");
            if ($paymentIntentId || $targetSubId) {
                $query = DB::table('subscriptions');
                if ($paymentIntentId) {
                    $query->where('payment_intent_id', $paymentIntentId);
                } else {
                    $query->where('id', $targetSubId);
                }

                $query->update([
                    'status' => 'failed',
                    'updated_at' => now()
                ]);
            }
        }

        // --- CASE 3: QR CODE EXPIRED (30 mins passed) ---
        elseif ($type === 'qrph.expired') {
            Log::info("QR Expired for Sub ID: {$targetSubId}");
            if ($paymentIntentId || $targetSubId) {
                $query = DB::table('subscriptions')->where('status', 'pending');
                if ($paymentIntentId) {
                    $query->where('payment_intent_id', $paymentIntentId);
                } else {
                    $query->where('id', $targetSubId);
                }

                $query->update([
                        'status' => 'expired',
                        'updated_at' => now()
                ]);
            }
        }

        // 3. Always return 200 to PayMongo
        return response()->json(['message' => 'Processed'], 200);
    }
}
