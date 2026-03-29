<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Support\Facades\Log;

class PayMongoController extends Controller
{
    private $secretKey;

    public function __construct(private SubscriptionLifecycleService $subscriptionLifecycleService) {
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

    private function resolveOwnerPlanChangeContext(User $user): array
    {
        $owner = DB::table('owners')->where('user_id', $user->id)->first();
        if (!$owner) {
            abort(response()->json(['message' => 'Owner profile not found'], 404));
        }

        $snapshot = $this->subscriptionLifecycleService->getOwnerSubscriptionSnapshotByUserId($user->id);
        if (empty($snapshot['plan_name'])) {
            abort(response()->json(['message' => 'No active or previous subscription found for plan change.'], 403));
        }

        if (!($snapshot['can_change_plan'] ?? false)) {
            abort(response()->json([
                'message' => $snapshot['plan_change_message'] ?? 'Plan changes become available in the last 7 days of your subscription or after expiry.',
                'subscription' => $snapshot,
            ], 403));
        }

        return [$owner, $snapshot];
    }

    private function getPlanPricing(string $plan): array
    {
        if ($plan === 'Monthly') {
            return [1, 'monthly', 2];
        }

        return [1, 'annual', 5];
    }


    private function resolvePendingSubscriptionIds(?string $paymentIntentId, ?int $targetSubId): array
    {
        $query = DB::table('subscriptions')->where('status', 'pending');

        if ($paymentIntentId) {
            $query->where('payment_intent_id', $paymentIntentId);
        } elseif ($targetSubId) {
            $query->where('id', $targetSubId);
        } else {
            return [];
        }

        return array_map('intval', $query->pluck('id')->all());
    }

    private function finalizePendingSubscriptions(array $subscriptionIds, string $finalStatus): void
    {
        foreach ($subscriptionIds as $subscriptionId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, $finalStatus);
        }
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
            'permit_acknowledged' => 'required|accepted',
        ]);

        // 1. Calculate Plan Details
        [$amount, $billing, $listingLimit] = $this->getPlanPricing($request->plan);

        $existingOwner = DB::table('owners')->where('user_id', $user->id)->first();

        // 2. Insert Pending Subscription into DB
        // We do this first so we have a record even if they don't pay yet
        try {
            DB::beginTransaction();

            $ownerData = [
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
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to create payment intent',
                'details' => $intentResponse->json(),
            ], 502);
        }

        $intent = $intentResponse->json();
        $intentId = $intent['data']['id'] ?? null;

        if (!$intentId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

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
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to create payment method',
                'details' => $methodResponse->json(),
            ], 502);
        }

        $method = $methodResponse->json();
        $paymentMethodId = $method['data']['id'] ?? null;

        if (!$paymentMethodId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json(['error' => 'Payment method id missing'], 502);
        }

        $attachResponse = $this->payMongoHttp()
        ->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                    'return_url' => rtrim(config('app.frontend_url'), '/') . '/subscription/renew'
                ]
            ]
        ]);

        if (!$attachResponse->successful()) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

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

    public function createRenewalPayment(Request $request) {
        $user = Auth::user();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only owners can renew subscriptions'], 403);
        }

        $request->validate([
            'plan' => 'required|in:Monthly,Annual',
            'permit_acknowledged' => 'required|accepted',
        ]);

        [$amount, $billing, $listingLimit] = $this->getPlanPricing($request->plan);

        $owner = DB::table('owners')->where('user_id', $user->id)->first();
        if (!$owner) {
            return response()->json(['message' => 'Owner profile not found'], 404);
        }

        try {
            DB::beginTransaction();

            $subscriptionId = DB::table('subscriptions')->insertGetId([
                'user_id' => $user->id,
                'owner_id' => $owner->id,
                'plan_name' => $request->plan,
                'amount' => $amount,
                'billing_cycle' => $billing,
                'listing_limit' => $listingLimit,
                'start_date' => Carbon::now(),
                'payment_provider' => 'paymongo',
                'payment_method' => 'qrph',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to initiate renewal: ' . $e->getMessage()], 500);
        }

        $intentResponse = $this->payMongoHttp()
        ->post('https://api.paymongo.com/v1/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $amount * 100,
                    'payment_method_allowed' => ['qrph'],
                    'currency' => 'PHP',
                    'description' => $subscriptionId,
                ]
            ]
        ]);

        if (!$intentResponse->successful()) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to create payment intent',
                'details' => $intentResponse->json(),
            ], 502);
        }

        $intent = $intentResponse->json();
        $intentId = $intent['data']['id'] ?? null;

        if (!$intentId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

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
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to create payment method',
                'details' => $methodResponse->json(),
            ], 502);
        }

        $method = $methodResponse->json();
        $paymentMethodId = $method['data']['id'] ?? null;

        if (!$paymentMethodId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json(['error' => 'Payment method id missing'], 502);
        }

        $attachResponse = $this->payMongoHttp()
        ->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                    'return_url' => rtrim(config('app.frontend_url'), '/') . '/subscription/renew'
                ]
            ]
        ]);

        if (!$attachResponse->successful()) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to attach payment method',
                'details' => $attachResponse->json(),
            ], 502);
        }

        $attach = $attachResponse->json();
        $qrCode = $attach['data']['attributes']['next_action']['code']['image_url'] ?? null;

        if (!$qrCode) {
            $qrCode = $attach['data']['attributes']['next_action']['render_qr_code'] ?? null;
        }

        return response()->json([
            'qr_code' => $qrCode,
            'subscription_id' => $subscriptionId,
            'full_response' => $attach
        ]);
    }


    public function createPlanChangePayment(Request $request) {
        $user = Auth::user();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only owners can change subscription plans'], 403);
        }

        $request->validate([
            'plan' => 'required|in:Monthly,Annual',
            'permit_acknowledged' => 'required|accepted',
            'change_acknowledged' => 'required|accepted',
        ]);

        try {
            [$owner, $snapshot] = $this->resolveOwnerPlanChangeContext($user);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        }

        $currentCycle = strtolower((string) ($snapshot['billing_cycle'] ?? ''));
        $requestedPlan = $request->plan;
        $requestedCycle = $requestedPlan === 'Annual' ? 'annual' : 'monthly';

        if ($requestedCycle === $currentCycle) {
            return response()->json(['message' => 'Select a different plan to continue.'], 422);
        }

        if (!$request->boolean('change_acknowledged')) {
            return response()->json([
                'message' => 'Please acknowledge the plan change notice before continuing.',
            ], 422);
        }

        [$amount, $billing, $listingLimit] = $this->getPlanPricing($requestedPlan);

        try {
            DB::beginTransaction();

            $subscriptionId = DB::table('subscriptions')->insertGetId([
                'user_id' => $user->id,
                'owner_id' => $owner->id,
                'plan_name' => $requestedPlan,
                'amount' => $amount,
                'billing_cycle' => $billing,
                'listing_limit' => $listingLimit,
                'start_date' => Carbon::now(),
                'payment_provider' => 'paymongo',
                'payment_method' => 'qrph',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to initiate plan change: ' . $e->getMessage()], 500);
        }

        $intentResponse = $this->payMongoHttp()
            ->post('https://api.paymongo.com/v1/payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount' => $amount * 100,
                        'payment_method_allowed' => ['qrph'],
                        'currency' => 'PHP',
                        'description' => $subscriptionId,
                    ]
                ]
            ]);

        if (!$intentResponse->successful()) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to create payment intent',
                'details' => $intentResponse->json(),
            ], 502);
        }

        $intent = $intentResponse->json();
        $intentId = $intent['data']['id'] ?? null;

        if (!$intentId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

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
                            'name' => $user->first_name . ' ' . $user->last_name,
                            'email' => $user->email,
                        ]
                    ]
                ]
            ]);

        if (!$methodResponse->successful()) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to create payment method',
                'details' => $methodResponse->json(),
            ], 502);
        }

        $method = $methodResponse->json();
        $paymentMethodId = $method['data']['id'] ?? null;

        if (!$paymentMethodId) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json(['error' => 'Payment method id missing'], 502);
        }

        $attachResponse = $this->payMongoHttp()
            ->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", [
                'data' => [
                    'attributes' => [
                        'payment_method' => $paymentMethodId,
                        'return_url' => rtrim(config('app.frontend_url'), '/') . '/subscription/change?plan=' . strtolower($billing),
                    ]
                ]
            ]);

        if (!$attachResponse->successful()) {
            $this->subscriptionLifecycleService->resolvePendingSubscriptionAttempt((int) $subscriptionId, 'failed');

            return response()->json([
                'error' => 'Failed to attach payment method',
                'details' => $attachResponse->json(),
            ], 502);
        }

        $attach = $attachResponse->json();
        $qrCode = $attach['data']['attributes']['next_action']['code']['image_url'] ?? null;

        if (!$qrCode) {
            $qrCode = $attach['data']['attributes']['next_action']['render_qr_code'] ?? null;
        }

        return response()->json([
            'qr_code' => $qrCode,
            'subscription_id' => $subscriptionId,
            'full_response' => $attach,
        ]);
    }

    public function createListingAddonIntent(Request $request)
    {
        $request->validate([
            'qty' => 'required|integer|min:1|max:10',
        ]);

        $user = Auth::user();
        $owner = DB::table('owners')->where('user_id', $user->id)->first();
        if (!$owner) {
            return response()->json(['message' => 'Owner profile not found'], 404);
        }

        $activeSubscription = DB::table('subscriptions')
            ->where('owner_id', $owner->id)
            ->where('status', 'active')
            ->whereDate('end_date', '>=', now()->toDateString())
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();

        if (!$activeSubscription) {
            return response()->json(['message' => 'No active subscription found'], 403);
        }

        $billingCycle = $activeSubscription->billing_cycle;
        $unitPrice = $billingCycle === 'annual' ? 450 : 50;
        $qty = (int) $request->qty;
        // $totalAmount = $qty * $unitPrice;
        $totalAmount = 1; // TEMP for testing, replace with above line in production

        DB::beginTransaction();
        try {
            $addonId = DB::table('listing_limit_addons')->insertGetId([
                'owner_id' => $owner->id,
                'subscription_id' => $activeSubscription->id,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'billing_cycle' => $billingCycle,
                'payment_provider' => 'paymongo',
                'payment_method' => 'qrph',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $intentResponse = $this->payMongoHttp()
                ->post('https://api.paymongo.com/v1/payment_intents', [
                    'data' => [
                        'attributes' => [
                            'amount' => $totalAmount * 100,
                            'payment_method_allowed' => ['qrph'],
                            'currency' => 'PHP',
                            'description' => $addonId,
                        ]
                    ]
                ]);

            if (!$intentResponse->successful()) {
                DB::table('listing_limit_addons')->where('id', $addonId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
                DB::rollBack();
                return response()->json([
                    'error' => 'Failed to create payment intent',
                    'details' => $intentResponse->json(),
                ], 502);
            }

            $intent = $intentResponse->json();
            $intentId = $intent['data']['id'] ?? null;
            if (!$intentId) {
                DB::table('listing_limit_addons')->where('id', $addonId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
                DB::rollBack();
                return response()->json(['error' => 'Payment intent id missing'], 502);
            }

            DB::table('listing_limit_addons')->where('id', $addonId)->update([
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
                DB::table('listing_limit_addons')->where('id', $addonId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
                DB::rollBack();
                return response()->json([
                    'error' => 'Failed to create payment method',
                    'details' => $methodResponse->json(),
                ], 502);
            }

            $method = $methodResponse->json();
            $paymentMethodId = $method['data']['id'] ?? null;
            if (!$paymentMethodId) {
                DB::table('listing_limit_addons')->where('id', $addonId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
                DB::rollBack();
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
                DB::table('listing_limit_addons')->where('id', $addonId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
                DB::rollBack();
                return response()->json([
                    'error' => 'Failed to attach payment method',
                    'details' => $attachResponse->json(),
                ], 502);
            }

            $attach = $attachResponse->json();
            $qrCode = $attach['data']['attributes']['next_action']['code']['image_url'] ?? null;
            if (!$qrCode) {
                $qrCode = $attach['data']['attributes']['next_action']['render_qr_code'] ?? null;
            }

            DB::commit();

            return response()->json([
                'qr_code' => $qrCode,
                'addon_id' => $addonId,
                'payment_intent_id' => $intentId,
                'full_response' => $attach,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create listing add-on intent',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getListingAddonStatus(int $addonId)
    {
        $ownerId = DB::table('owners')->where('user_id', Auth::id())->value('id');
        if (!$ownerId) {
            return response()->json(['message' => 'Owner not found'], 404);
        }

        $addon = DB::table('listing_limit_addons')
            ->where('id', $addonId)
            ->where('owner_id', $ownerId)
            ->first();

        if (!$addon) {
            return response()->json(['message' => 'Add-on not found'], 404);
        }

        return response()->json([
            'status' => $addon->status,
        ], 200);
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
            $addon = null;

            if (!$sub) {
                $addonQuery = DB::table('listing_limit_addons');
                if ($paymentIntentId) {
                    $addonQuery->where('payment_intent_id', $paymentIntentId);
                } elseif ($targetSubId) {
                    $addonQuery->where('id', $targetSubId);
                }
                $addon = $addonQuery->first();
            }

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

                    if ($sub->owner_id) {
                        $owner = DB::table('owners')->where('id', $sub->owner_id)->first();
                        if (!$owner) {
                            $owner = DB::table('owners')->where('user_id', $user->id)->first();
                        }

                        if (!$owner) {
                            DB::rollBack();
                            Log::warning("Webhook owner not found for renewal sub {$sub->id}");
                            return response()->json(['message' => 'Owner not found'], 200);
                        }

                        $today = Carbon::today();
                        $targetSubscription = DB::table('subscriptions')
                            ->where('owner_id', $owner->id)
                            ->whereIn('status', ['active', 'expired'])
                            ->orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
                            ->orderByDesc('end_date')
                            ->orderByDesc('id')
                            ->first();

                        $isPlanChange = $targetSubscription
                            && strtolower((string) $targetSubscription->billing_cycle) !== strtolower((string) $sub->billing_cycle);

                        $periodStart = $today;
                        if (!$isPlanChange && $targetSubscription && $targetSubscription->status === 'active' && $targetSubscription->end_date) {
                            $targetEnd = Carbon::parse($targetSubscription->end_date);
                            if ($targetEnd->greaterThanOrEqualTo($today)) {
                                $periodStart = $targetEnd->copy()->addDay();
                            }
                        }

                        $periodEnd = $sub->billing_cycle === 'monthly'
                            ? Carbon::parse($periodStart)->addMonth()
                            : Carbon::parse($periodStart)->addYear();

                        if ($targetSubscription) {
                            DB::table('subscriptions')->where('id', $targetSubscription->id)->update([
                                'plan_name' => $sub->plan_name,
                                'amount' => $sub->amount,
                                'billing_cycle' => $sub->billing_cycle,
                                'listing_limit' => $sub->listing_limit,
                                'payment_provider' => $sub->payment_provider,
                                'payment_method' => $sub->payment_method,
                                'start_date' => $periodStart,
                                'end_date' => $periodEnd,
                                'status' => 'active',
                                'warning_sent_at' => null,
                                'updated_at' => now(),
                            ]);
                            $activeSubscriptionId = $targetSubscription->id;
                        } else {
                            DB::table('subscriptions')->where('id', $sub->id)->update([
                                'owner_id' => $owner->id,
                                'plan_name' => $sub->plan_name,
                                'amount' => $sub->amount,
                                'billing_cycle' => $sub->billing_cycle,
                                'listing_limit' => $sub->listing_limit,
                                'payment_provider' => $sub->payment_provider,
                                'payment_method' => $sub->payment_method,
                                'start_date' => $periodStart,
                                'end_date' => $periodEnd,
                                'status' => 'active',
                                'warning_sent_at' => null,
                                'updated_at' => now(),
                            ]);
                            $activeSubscriptionId = $sub->id;
                        }

                        DB::table('subscription_history')->insert([
                            'subscription_id' => $activeSubscriptionId,
                            'user_id' => $sub->user_id,
                            'owner_id' => $owner->id,
                            'action' => $isPlanChange ? 'plan_change' : 'renewal',
                            'plan_name' => $sub->plan_name,
                            'billing_cycle' => $sub->billing_cycle,
                            'amount' => $sub->amount,
                            'period_start' => $periodStart,
                            'period_end' => $periodEnd,
                            'payment_reference' => $sub->payment_intent_id ?? $paymentIntentId,
                            'payment_provider' => $sub->payment_provider,
                            'payment_method' => $sub->payment_method,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        if ($activeSubscriptionId !== $sub->id) {
                            DB::table('subscriptions')->where('id', $sub->id)->update([
                                'status' => 'cancelled',
                                'updated_at' => now(),
                            ]);
                        }

                        DB::commit();
                        Log::info("Subscription renewed for owner #{$owner->id}");
                    } else {
                        $endDate = $sub->billing_cycle === 'monthly' ? now()->addMonth() : now()->addYear();

                        DB::table('subscriptions')->where('id', $sub->id)->update([
                            'status' => 'active',
                            'start_date' => now()->toDateString(),
                            'end_date' => $endDate,
                            'updated_at' => now()
                        ]);

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

                        DB::table('subscriptions')->where('id', $sub->id)->update(['owner_id' => $owner->id]);

                        $user->update(['role' => 'owner']);

                        DB::table('subscription_history')->insert([
                            'subscription_id' => $sub->id,
                            'user_id' => $sub->user_id,
                            'owner_id' => $owner->id,
                            'action' => 'activation',
                            'plan_name' => $sub->plan_name,
                            'billing_cycle' => $sub->billing_cycle,
                            'amount' => $sub->amount,
                            'period_start' => now()->toDateString(),
                            'period_end' => $endDate,
                            'payment_reference' => $sub->payment_intent_id ?? $paymentIntentId,
                            'payment_provider' => $sub->payment_provider,
                            'payment_method' => $sub->payment_method,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::commit();
                        Log::info("User {$user->email} successfully upgraded for Sub #{$sub->id}");
                    }
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Webhook DB Error: ' . $e->getMessage());
                    return response()->json(['error' => 'Processing failed'], 500);
                }
            } elseif ($addon) {
                DB::beginTransaction();
                try {
                    if ($addon->status === 'active') {
                        DB::commit();
                        return response()->json(['message' => 'Already processed'], 200);
                    }

                    if ($addon->status !== 'pending') {
                        DB::commit();
                        return response()->json(['message' => 'No pending add-on to activate'], 200);
                    }

                    DB::table('listing_limit_addons')->where('id', $addon->id)->update([
                        'status' => 'active',
                        'applied_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('subscriptions')
                        ->where('id', $addon->subscription_id)
                        ->update([
                            'listing_limit' => DB::raw('listing_limit + ' . (int) $addon->qty),
                            'updated_at' => now(),
                        ]);

                    DB::commit();
                    Log::info("Listing add-on applied for Addon #{$addon->id}");
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Webhook Add-on DB Error: ' . $e->getMessage());
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
                $this->finalizePendingSubscriptions(
                    $this->resolvePendingSubscriptionIds($paymentIntentId, $targetSubId),
                    'failed'
                );

                $addonQuery = DB::table('listing_limit_addons');
                if ($paymentIntentId) {
                    $addonQuery->where('payment_intent_id', $paymentIntentId);
                } else {
                    $addonQuery->where('id', $targetSubId);
                }

                $addonQuery->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            }
        }

        // --- CASE 3: QR CODE EXPIRED (30 mins passed) ---
        elseif ($type === 'qrph.expired') {
            Log::info("QR Expired for Sub ID: {$targetSubId}");
            if ($paymentIntentId || $targetSubId) {
                $this->finalizePendingSubscriptions(
                    $this->resolvePendingSubscriptionIds($paymentIntentId, $targetSubId),
                    'expired'
                );

                $addonQuery = DB::table('listing_limit_addons')->where('status', 'pending');
                if ($paymentIntentId) {
                    $addonQuery->where('payment_intent_id', $paymentIntentId);
                } else {
                    $addonQuery->where('id', $targetSubId);
                }

                $addonQuery->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);
            }
        }

        // 3. Always return 200 to PayMongo
        return response()->json(['message' => 'Processed'], 200);
    }
}
