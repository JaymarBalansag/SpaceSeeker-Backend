<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    private function getOwnerId()
    {
        return DB::table('owners')->where('user_id', Auth::id())->value('id');
    }

    private function resolveLatestBillingForTenant($tenantId, $propertyId)
    {
        return DB::table('billings')
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->orderByDesc('rent_due')
            ->first();
    }

    private function getVerifiedPaidSum($billingId, $type)
    {
        return (float) DB::table('payments')
            ->where('billing_id', $billingId)
            ->where('status', 'verified')
            ->where(function ($q) use ($type) {
                if ($type === 'rent') {
                    $q->whereNull('remarks')->orWhere('remarks', 'like', 'payment_type:rent%');
                } else {
                    $q->where('remarks', 'like', 'payment_type:' . $type . '%');
                }
            })
            ->sum('amount_paid');
    }
    public function getOwnerDashboardSummary()
    {
        try {
            $ownerId = $this->getOwnerId();

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
            $ownerId = $this->getOwnerId();

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
        $ownerId = $this->getOwnerId();

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

    public function getLedgerPayments(Request $request)
    {
        $ownerId = $this->getOwnerId();
        if (!$ownerId) {
            return response()->json(['data' => [], 'message' => 'Owner profile not found.'], 404);
        }

        $propertyId = $request->query('property_id');
        $tenantId = $request->query('tenant_id');
        $tenantSearch = $request->query('tenant_search');
        $paymentType = $request->query('payment_type');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = DB::table('payments')
            ->join('billings', 'payments.billing_id', '=', 'billings.id')
            ->join('tenants', 'billings.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->join('properties', 'billings.property_id', '=', 'properties.id')
            ->where('properties.owner_id', $ownerId)
            ->select(
                'payments.id',
                'payments.amount_paid as amount',
                'payments.date_paid',
                'payments.created_at',
                'payments.remarks',
                'payments.payment_method',
                'billings.property_id',
                'billings.tenant_id',
                'properties.title as property_title',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as tenant_name"),
                DB::raw("
                    CASE
                        WHEN payments.remarks LIKE 'payment_type:deposit%' THEN 'deposit'
                        WHEN payments.remarks LIKE 'payment_type:advance%' THEN 'advance'
                        WHEN payments.remarks LIKE 'payment_type:rent%' THEN 'rent'
                        ELSE 'rent'
                    END as payment_type
                ")
            )
            ->orderBy('payments.created_at', 'desc');

        if (!empty($propertyId)) {
            $query->where('billings.property_id', $propertyId);
        }

        if (!empty($tenantId)) {
            $query->where('billings.tenant_id', $tenantId);
        }

        if (!empty($tenantSearch)) {
            $query->where(function ($q) use ($tenantSearch) {
                $q->where('users.first_name', 'like', '%' . $tenantSearch . '%')
                  ->orWhere('users.last_name', 'like', '%' . $tenantSearch . '%');
            });
        }

        if (!empty($paymentType)) {
            if ($paymentType === 'manual') {
                $query->whereNull('payments.remarks');
            } else {
                $query->where('payments.remarks', 'like', 'payment_type:' . $paymentType . '%');
            }
        }

        if (!empty($dateFrom)) {
            $query->whereDate('payments.date_paid', '>=', $dateFrom);
        }
        if (!empty($dateTo)) {
            $query->whereDate('payments.date_paid', '<=', $dateTo);
        }

        $payments = $query->get();
        return response()->json(['data' => $payments]);
    }

    public function verifyPayment($paymentId){
        try {
            return DB::transaction(function () use ($paymentId) {
                $ownerId = $this->getOwnerId();
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
                    'deposit_paid_amount' => $billinginfo->deposit_paid_amount ?? 0,
                    'advance_paid_amount' => $billinginfo->advance_paid_amount ?? 0,
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
                $ownerId = $this->getOwnerId();
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

    public function getLedgerTenants(Request $request)
    {
        $ownerId = $this->getOwnerId();
        if (!$ownerId) {
            return response()->json(['message' => 'Owner profile not found.'], 404);
        }

        $propertyId = $request->query('property_id');
        if (!$propertyId) {
            return response()->json(['message' => 'property_id is required.'], 422);
        }

        $tenants = DB::table('tenants')
            ->join('properties', 'tenants.property_id', '=', 'properties.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->select(
                'tenants.id',
                'tenants.user_id',
                'tenants.status',
                'users.first_name',
                'users.last_name',
                'users.email'
            )
            ->where('properties.owner_id', $ownerId)
            ->where('tenants.property_id', $propertyId)
            ->where('tenants.status', 'active')
            ->orderBy('users.first_name')
            ->get();

        return response()->json(['data' => $tenants], 200);
    }

    public function getLedgerDues(Request $request)
    {
        $ownerId = $this->getOwnerId();
        if (!$ownerId) {
            return response()->json(['message' => 'Owner profile not found.'], 404);
        }

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'property_id' => 'required|integer',
        ]);

        $tenant = DB::table('tenants')
            ->join('properties', 'tenants.property_id', '=', 'properties.id')
            ->select('tenants.id', 'tenants.property_id', 'properties.deposit_required', 'properties.advance_payment_months')
            ->where('tenants.id', $validated['tenant_id'])
            ->where('properties.owner_id', $ownerId)
            ->where('properties.id', $validated['property_id'])
            ->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found for this owner/property.'], 404);
        }

        $billings = DB::table('billings')
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $tenant->property_id)
            ->orderBy('rent_due', 'desc')
            ->get();

        $dues = [];

        foreach ($billings as $bill) {
            if (!in_array($bill->rent_status, ['unpaid', 'overdue', 'pending'], true)) {
                continue;
            }

            $paid = $this->getVerifiedPaidSum($bill->id, 'rent');
            $remaining = round(((float) $bill->rent_amount) - $paid, 2);
            if ($remaining <= 0) {
                continue;
            }

            $dues[] = [
                'id' => 'rent_' . $bill->id,
                'type' => 'rent',
                'label' => $bill->rent_cycle . ' Rent',
                'amount' => (float) $bill->rent_amount,
                'amount_due' => $remaining,
                'billing_id' => $bill->id,
                'property_id' => $bill->property_id,
                'rent_status' => $bill->rent_status,
            ];
        }

        $latestBilling = $billings->first();

        $depositRequired = (float) $tenant->deposit_required;
        if ($depositRequired > 0) {
            $depositPaid = $latestBilling ? (float) ($latestBilling->deposit_paid_amount ?? 0) : 0.0;
            $depositRemaining = round($depositRequired - $depositPaid, 2);
            if ($depositRemaining > 0) {
                $dues[] = [
                    'id' => 'deposit_' . $tenant->id,
                    'type' => 'deposit',
                    'label' => 'Security Deposit',
                    'amount' => $depositRequired,
                    'amount_due' => $depositRemaining,
                    'billing_id' => $latestBilling ? $latestBilling->id : null,
                    'property_id' => $tenant->property_id,
                ];
            }
        }

        $advanceAmount = (float) $tenant->advance_payment_months;
        if ($advanceAmount > 0) {
            $advanceRequired = round($advanceAmount, 2);
            $advancePaid = $latestBilling ? (float) ($latestBilling->advance_paid_amount ?? 0) : 0.0;
            $advanceRemaining = round($advanceRequired - $advancePaid, 2);
            if ($advanceRemaining > 0) {
                $dues[] = [
                    'id' => 'advance_' . $tenant->id,
                    'type' => 'advance',
                    'label' => 'Advance Payment (Move-out Notice)',
                    'amount' => $advanceRequired,
                    'amount_due' => $advanceRemaining,
                    'billing_id' => $latestBilling ? $latestBilling->id : null,
                    'property_id' => $tenant->property_id,
                ];
            }
        }

        return response()->json(['data' => $dues], 200);
    }

    public function createLedgerPayment(Request $request)
    {
        $ownerId = $this->getOwnerId();
        if (!$ownerId) {
            return response()->json(['message' => 'Owner profile not found.'], 404);
        }

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'property_id' => 'required|integer',
            'payment_type' => 'required|string|in:rent,deposit,advance',
            'amount' => 'required|numeric|min:0.01',
            'billing_id' => 'nullable|integer',
            'paid_at' => 'nullable|date',
        ]);

        $tenant = DB::table('tenants')
            ->join('properties', 'tenants.property_id', '=', 'properties.id')
            ->select('tenants.id', 'tenants.property_id')
            ->where('tenants.id', $validated['tenant_id'])
            ->where('properties.owner_id', $ownerId)
            ->where('properties.id', $validated['property_id'])
            ->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found for this owner/property.'], 404);
        }

        $billing = null;
        if ($validated['payment_type'] === 'rent') {
            if (!$validated['billing_id']) {
                return response()->json(['message' => 'billing_id is required for rent payments.'], 422);
            }
            $billing = DB::table('billings')
                ->where('id', $validated['billing_id'])
                ->where('tenant_id', $tenant->id)
                ->where('property_id', $tenant->property_id)
                ->first();
        } else {
            $billing = $this->resolveLatestBillingForTenant($tenant->id, $tenant->property_id);
        }

        if (!$billing) {
            return response()->json(['message' => 'Billing record not found.'], 404);
        }

        $property = DB::table('properties')
            ->where('id', $tenant->property_id)
            ->select('deposit_required', 'advance_payment_months')
            ->first();

        if ($validated['payment_type'] === 'rent') {
            if ($billing->rent_status === 'paid') {
                return response()->json(['message' => 'This billing is already paid.'], 422);
            }
            $paidSum = $this->getVerifiedPaidSum($billing->id, 'rent');
            $remaining = round(((float) $billing->rent_amount) - $paidSum, 2);
            if ($remaining <= 0) {
                return response()->json(['message' => 'This billing is already fully covered.'], 422);
            }
            if (round((float) $validated['amount'], 2) > $remaining) {
                return response()->json(['message' => 'Amount exceeds remaining balance.'], 422);
            }
        } elseif ($validated['payment_type'] === 'deposit') {
            $required = $property ? round((float) $property->deposit_required, 2) : 0;
            if ($required <= 0) {
                return response()->json(['message' => 'Payment type not applicable.'], 422);
            }
            $remaining = round($required - (float) ($billing->deposit_paid_amount ?? 0), 2);
            if ($remaining <= 0) {
                return response()->json(['message' => 'Security deposit already covered.'], 422);
            }
            if (round((float) $validated['amount'], 2) > $remaining) {
                return response()->json(['message' => 'Amount exceeds remaining balance.'], 422);
            }
        } elseif ($validated['payment_type'] === 'advance') {
            $required = $property ? round((float) $property->advance_payment_months, 2) : 0;
            if ($required <= 0) {
                return response()->json(['message' => 'Payment type not applicable.'], 422);
            }
            $remaining = round($required - (float) ($billing->advance_paid_amount ?? 0), 2);
            if ($remaining <= 0) {
                return response()->json(['message' => 'Advance payment already covered.'], 422);
            }
            if (round((float) $validated['amount'], 2) > $remaining) {
                return response()->json(['message' => 'Amount exceeds remaining balance.'], 422);
            }
        }

        DB::table('payments')->insert([
            'billing_id' => $billing->id,
            'amount_paid' => round((float) $validated['amount'], 2),
            'payment_method' => 'Personal',
            'payment_reference' => null,
            'proof' => null,
            'remarks' => 'payment_type:' . $validated['payment_type'],
            'status' => 'verified',
            'date_paid' => $validated['paid_at'] ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($validated['payment_type'] === 'rent') {
            $paidSum = $this->getVerifiedPaidSum($billing->id, 'rent');
            if (round(((float) $billing->rent_amount) - $paidSum, 2) <= 0) {
                DB::table('billings')
                    ->where('id', $billing->id)
                    ->update([
                        'rent_status' => 'paid',
                        'updated_at' => now(),
                    ]);
            }
        } elseif ($validated['payment_type'] === 'deposit') {
            DB::table('billings')
                ->where('id', $billing->id)
                ->update([
                    'deposit_paid_amount' => DB::raw('deposit_paid_amount + ' . round((float) $validated['amount'], 2)),
                    'updated_at' => now(),
                ]);
        } elseif ($validated['payment_type'] === 'advance') {
            DB::table('billings')
                ->where('id', $billing->id)
                ->update([
                    'advance_paid_amount' => DB::raw('advance_paid_amount + ' . round((float) $validated['amount'], 2)),
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['message' => 'Payment recorded successfully.'], 201);
    }

}
