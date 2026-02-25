<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function getOwnerDashboardSummary()
    {
        try {
            $ownerId = DB::table("owners")
                ->where("user_id", Auth::id())
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    'message' => 'Owner profile not found.',
                    'data' => null
                ], 404);
            }

            $propertyCount = DB::table('properties')
                ->where('owner_id', $ownerId)
                ->count();

            $activeTenantCount = DB::table('tenants')
                ->join('properties', 'tenants.property_id', '=', 'properties.id')
                ->where('properties.owner_id', $ownerId)
                ->where('tenants.status', 'active')
                ->count();

            $pendingBookingCount = DB::table('bookings')
                ->join('properties', 'bookings.property_id', '=', 'properties.id')
                ->where('properties.owner_id', $ownerId)
                ->where('bookings.status', 'pending')
                ->count();

            $pendingPaymentCount = DB::table('payments')
                ->join('billings', 'payments.billing_id', '=', 'billings.id')
                ->join('properties', 'billings.property_id', '=', 'properties.id')
                ->where('properties.owner_id', $ownerId)
                ->where('payments.status', 'pending')
                ->count();

            $monthlyVerifiedTotal = (float) DB::table('payments')
                ->join('billings', 'payments.billing_id', '=', 'billings.id')
                ->join('properties', 'billings.property_id', '=', 'properties.id')
                ->where('properties.owner_id', $ownerId)
                ->where('payments.status', 'verified')
                ->whereMonth('payments.created_at', now()->month)
                ->whereYear('payments.created_at', now()->year)
                ->sum('payments.amount_paid');

            return response()->json([
                'message' => 'Owner dashboard summary retrieved successfully.',
                'data' => [
                    'properties_count' => $propertyCount,
                    'active_tenants_count' => $activeTenantCount,
                    'pending_bookings_count' => $pendingBookingCount,
                    'pending_payments_count' => $pendingPaymentCount,
                    'monthly_verified_total' => round($monthlyVerifiedTotal, 2),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to fetch owner dashboard summary.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getBillings(Request $request)
    {
        try {
            $loggedInUser = Auth::id();
            $ownerId = DB::table("owners")
                ->where("user_id", "=", $loggedInUser)
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    'message' => 'Owner profile not found.'
                ], 404);
            }

            $status = $request->query('status', 'all'); // default = all
            $propertyId = $request->query('property_id');
            $perPage = 10;

            $query = DB::table('billings')
                ->join('properties', 'billings.property_id', '=', 'properties.id')
                ->join('tenants', 'billings.tenant_id', '=', 'tenants.id')
                ->join('users', 'tenants.user_id', '=', 'users.id')
                ->where('properties.owner_id', $ownerId)
                ->select(
                    'billings.*',
                    'properties.title as property_name',
                    'users.first_name',
                    'users.last_name',
                )
                ->orderBy('billings.rent_due', 'asc');

            // Filter by rent status
            if ($status !== 'all') {
                $query->where('billings.rent_status', $status);
            }

            if (!empty($propertyId)) {
                $query->where('billings.property_id', $propertyId);
            }

            // Pagination
            $billings = $query->paginate($perPage);

            return response()->json($billings, 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch billings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPayments()
    {
        $userId = Auth::id();
        $ownerId = DB::table("owners")
        ->where("user_id", "=", $userId)
        ->value("id");

        if (!$ownerId) {
            return response()->json(['data' => [], 'message' => 'Owner profile not found.'], 404);
        }

        $payments = DB::table('payments')
        ->join('billings', 'payments.billing_id', '=', 'billings.id')
        ->join('tenants', 'billings.tenant_id', '=', 'tenants.id')
        ->join('users', 'tenants.user_id', '=', 'users.id')
        ->join('properties', 'billings.property_id', '=', 'properties.id')
        ->where('properties.owner_id', $ownerId)
        ->select(
            'payments.*',
            'users.first_name', 'users.last_name',
            'billings.rent_cycle',
            'properties.title as property_title',
            DB::raw("
                    CASE 
                        WHEN payments.proof IS NOT NULL 
                        THEN CONCAT('" . asset('storage') . "/', payments.proof) 
                        ELSE NULL 
                    END as proof
                ")
        )
        ->orderBy('payments.created_at', 'desc')
        ->get();

        return response()->json(['data' => $payments]);
    }

    public function verifyPayment($paymentId){
        try {
            return DB::transaction(function () use ($paymentId) {
                $ownerId = DB::table('owners')->where('user_id', Auth::id())->value('id');
                if (!$ownerId) {
                    return response()->json(['message' => 'Owner profile not found.'], 404);
                }

                $payment = DB::table('payments')
                    ->join('billings', 'payments.billing_id', '=', 'billings.id')
                    ->join('properties', 'billings.property_id', '=', 'properties.id')
                    ->where('payments.id', $paymentId)
                    ->where('properties.owner_id', $ownerId)
                    ->select(
                        'payments.id as payment_id',
                        'payments.status as payment_status',
                        'payments.billing_id'
                    )
                    ->first();

                if (!$payment) {
                    return response()->json(['message' => 'Payment not found for this owner.'], 404);
                }

                if ($payment->payment_status !== 'pending') {
                    return response()->json(['message' => 'Only pending payments can be verified.'], 422);
                }

                $billinginfo = DB::table("billings")
                    ->join("tenants", "billings.tenant_id", "=", "tenants.id")
                    ->join("users", "tenants.user_id", "=", "users.id")
                    ->join("properties", "billings.property_id", "=", "properties.id")
                    ->where("billings.id", "=", $payment->billing_id)
                    ->where("properties.owner_id", "=", $ownerId)
                    ->select(
                        "billings.*",
                        "properties.payment_frequency",
                        "users.first_name",
                        "users.last_name",
                        "users.email"
                    )
                    ->first();

                if (!$billinginfo) {
                    return response()->json(['message' => 'Billing information not found.'], 404);
                }

                $fullName = $billinginfo->first_name . " " . $billinginfo->last_name;

                // 3. Update current payment (pending-only to keep transition safe)
                $updated = DB::table('payments')
                    ->where('id', $payment->payment_id)
                    ->where('status', 'pending')
                    ->update([
                    'status' => 'verified',
                    'updated_at' => now()
                ]);

                if (!$updated) {
                    return response()->json(['message' => 'Payment was already processed.'], 409);
                }

                // 4. Update current billing with snapshots and mark as paid
                DB::table('billings')->where('id', $payment->billing_id)->update([
                    'tenant_name_snapshot' => $fullName,
                    'tenant_email_snapshot' => $billinginfo->email,
                    'rent_status' => 'paid',
                    'updated_at' => now()
                ]);

                // 5. Calculate next dates using Carbon
                $nextStart = \Carbon\Carbon::parse($billinginfo->rent_start);
                $nextDue = \Carbon\Carbon::parse($billinginfo->rent_due);

                $frequency = strtolower($billinginfo->payment_frequency);

                // Using if/else as requested with corrected logic
                if ($frequency === 'monthly') {
                    $nextStart->addMonthNoOverflow();
                    $nextDue->addMonthNoOverflow();
                } elseif ($frequency === 'quarterly') {
                    $nextStart->addMonthsNoOverflow(3);
                    $nextDue->addMonthsNoOverflow(3);
                } elseif ($frequency === 'semi-annually') { // Added this for you
                    $nextStart->addMonthsNoOverflow(6);
                    $nextDue->addMonthsNoOverflow(6);
                } elseif ($frequency === 'yearly' || $frequency === 'annually') {
                    $nextStart->addYearNoOverflow(); // Corrected from 6 months
                    $nextDue->addYearNoOverflow();
                } elseif ($frequency === 'per_night' || $frequency === 'per_day') {
                    $nextStart->addDay();
                    $nextDue->addDay();
                } elseif ($frequency === 'weekly') {
                    $nextStart->addWeek();
                    $nextDue->addWeek();
                } else {
                    $nextStart->addMonthNoOverflow();
                    $nextDue->addMonthNoOverflow();
                }

                // 6. Create the NEXT billing only if no open billing exists for this cycle date.
                $hasOpenNextBilling = DB::table('billings')
                    ->where('tenant_id', $billinginfo->tenant_id)
                    ->where('property_id', $billinginfo->property_id)
                    ->whereDate('rent_start', $nextStart->toDateString())
                    ->whereIn('rent_status', ['pending', 'unpaid', 'overdue'])
                    ->exists();

                if (!$hasOpenNextBilling) {
                    DB::table("billings")->insert([
                        'tenant_id' => $billinginfo->tenant_id,
                        'property_id' => $billinginfo->property_id,
                        'tenant_name_snapshot' => $fullName,
                        'tenant_email_snapshot' => $billinginfo->email,
                        'rent_amount' => $billinginfo->rent_amount,
                        'rent_cycle' => $billinginfo->rent_cycle,
                        'rent_start' => $nextStart->toDateString(),
                        'rent_due' => $nextDue->toDateString(),
                        'rent_status' => 'unpaid',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                return response()->json(['message' => 'Payment verified and next billing created']);
            });
        } catch (\Throwable $th) {
            return response()->json(["error" => $th->getMessage()], 500);
        }
    }

    public function rejectPayment(Request $request, $paymentId)
    {
        try {
            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            return DB::transaction(function () use ($paymentId, $validated) {
                $ownerId = DB::table('owners')->where('user_id', Auth::id())->value('id');
                if (!$ownerId) {
                    return response()->json(['message' => 'Owner profile not found.'], 404);
                }

                $payment = DB::table('payments')
                    ->join('billings', 'payments.billing_id', '=', 'billings.id')
                    ->join('properties', 'billings.property_id', '=', 'properties.id')
                    ->where('payments.id', $paymentId)
                    ->where('properties.owner_id', $ownerId)
                    ->select('payments.*', 'billings.rent_status')
                    ->first();

                if (!$payment) {
                    return response()->json(['message' => 'Payment not found for this owner.'], 404);
                }

                if ($payment->status !== 'pending') {
                    return response()->json(['message' => 'Only pending payments can be rejected.'], 422);
                }

                $ownerReason = trim((string) ($validated['reason'] ?? ''));
                $existingRemarks = trim((string) ($payment->remarks ?? ''));
                $nextRemarks = $existingRemarks;
                if ($ownerReason !== '') {
                    $suffix = "[Owner rejection] {$ownerReason}";
                    $nextRemarks = $existingRemarks === '' ? $suffix : "{$existingRemarks}\n{$suffix}";
                }

                DB::table('payments')
                    ->where('id', $payment->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'rejected',
                        'remarks' => $nextRemarks,
                        'updated_at' => now(),
                    ]);

                DB::table('billings')
                    ->where('id', $payment->billing_id)
                    ->where('rent_status', 'pending')
                    ->update([
                        'rent_status' => 'unpaid',
                        'updated_at' => now(),
                    ]);

                return response()->json(['message' => 'Payment rejected.']);
            });
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Failed to reject payment', 'error' => $th->getMessage()], 500);
        }
    }

}
