<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TenantsController extends Controller
{

    private function getOwner(){
        // * Gets the authenticated User
        $user = Auth::user();

        // * Check if the user is an owner
        $isOwner = DB::table("owners")
        ->where("user_id", $user->id)
        ->first();

        return $isOwner;
    }

    private function getPropertyTypeByPropertyId(int $propertyId){
        $property = DB::table("properties")
        ->where("id", $propertyId)
        ->first();

        if($property){
            return $property->property_type_id;
        }

        return null;
    }

    public function SelectTenantsByProperty(int $propertyId){
        try {

            $propertyType = $this->getPropertyTypeByPropertyId($propertyId);

            if(!$propertyType){
                return response()->json([
                    "error" => "Invalid property ID or property not found"
                ], 404);
            }

            // * Gets the authenticated User
            $isOwner = $this->getOwner();

            if(!$isOwner){
                return response()->json([
                    "error" => "Only owners can access tenants"
                ], 403);
            }

            //TODO: Select Tenants by the property type filter in the tenants table

            // Gets the tenants from property using the property id given
            $tenants = DB::table("tenants")
            ->join("properties", "tenants.property_id", "=", "properties.id")
            ->join("users", "tenants.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "tenants.*",
                "users.first_name",
                "users.last_name",
                "users.email as tenant_email",
                "properties.title as property_title",
                "property_types.type_name as property_type_name"
            )
            ->where("properties.id", "=", $propertyId)
            ->where("properties.owner_id", "=", $isOwner->id)
            ->where("properties.property_type_id", "=", $propertyType)
            ->get();
            

            if($tenants->isEmpty()){
                return response()->json([
                    "message" => "No tenants found for the specified property and type"
                ], 200);
            }

            return response()->json([
                "message" => "Tenants retrieved successfully",
                "data" => $tenants
            ], 200);


        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "error" => "An error occurred while fetching tenants: " . $e->getMessage()
            ], 500);
        }
    }

    public function getAllTenants(){
        try {

            // * Gets the authenticated User
            $isOwner = $this->getOwner();

            if(!$isOwner){
                return response()->json([
                    "error" => "Only owners can access tenants"
                ], 403);
            }


            $tenants = DB::table("tenants")
            ->join("properties", "tenants.property_id", "=", "properties.id")
            ->join("users", "tenants.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "tenants.*",
                "users.first_name",
                "users.last_name",
                "users.email as tenant_email",
                "properties.title as property_title",
                "property_types.type_name as property_type_name"
            )
            ->where("properties.owner_id", "=", $isOwner->id)
            ->get();

            if($tenants->isEmpty()){
                return response()->json([
                    "message" => "No tenants found for your properties"
                ], 200);
            }

            return response()->json([
                "message" => "Tenants retrieved successfully",
                "data" => $tenants
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "error" => "An error occurred while fetching tenants: " . $e->getMessage()
            ], 500);
        }
    }

    public function moveInTenant(Request $request, $id)
    {
        try {
            $ownerId = DB::table("owners")
                ->where("user_id", Auth::id())
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Owner profile not found.'
                ], 404);
            }

            $tenant = DB::table('tenants')
                ->join('properties', 'tenants.property_id', '=', 'properties.id')
                ->select('tenants.id', 'tenants.status')
                ->where('tenants.id', $id)
                ->where('properties.owner_id', $ownerId)
                ->first();

            if (!$tenant) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tenant not found for this owner.'
                ], 404);
            }

            if ($tenant->status === 'active') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tenant is already active.'
                ], 200);
            }

            return DB::transaction(function () use ($id) {
                DB::table('tenants')
                    ->where('id', $id)
                    ->update([
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);

                DB::table('billings')
                    ->where('tenant_id', $id)
                    ->where('rent_status', 'pending')
                    ->update([
                        'rent_status' => 'unpaid',
                        'updated_at' => now()
                    ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Tenant is now active and billing has been issued.'
                ], 200);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Move-in failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getMyBillings(Request $request) {
        $userId = Auth::id(); 

        // 1. Get just the 'id' value. Using value('id') returns the string/int directly or null.
        $tenantId = DB::table("tenants")->where("user_id", $userId)->value("id");

        // 2. Security Check: If they aren't a tenant, don't even run the billing query
        if (!$tenantId) {
            return response()->json([
                'data' => [],
                'message' => 'No tenant profile found for this user.'
            ], 404);
        }

        // 3. Get the billings
        $billings = DB::table('billings')
            ->join('properties', 'billings.property_id', '=', 'properties.id')
            ->where('billings.tenant_id', $tenantId) // $tenantId is now the raw ID
            ->select(
                'billings.id',
                'billings.rent_amount',
                'billings.rent_due',
                'billings.rent_status',
                'billings.rent_cycle',
                'properties.title as property_title'
            )
            ->orderBy('billings.rent_due', 'desc')
            ->get();

        return response()->json(['data' => $billings]);
    }

    public function getTenantDashboard(Request $request)
    {
        $userId = Auth::id();

        $tenant = DB::table("tenants")
            ->join("properties", "tenants.property_id", "=", "properties.id")
            ->leftJoin("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "tenants.id as tenant_id",
                "tenants.status as tenant_status",
                "tenants.move_in_date",
                "tenants.property_id",
                "properties.title as property_title",
                "properties.deposit_required",
                "properties.advance_payment_months",
                "properties.village_name",
                "properties.town_name",
                "properties.state_name",
                "properties.region_name",
                "property_types.type_name as property_type"
            )
            ->where("tenants.user_id", $userId)
            ->first();

        if (!$tenant) {
            return response()->json([
                'data' => [],
                'message' => 'No tenant profile found for this user.'
            ], 404);
        }

        $addressParts = array_filter([
            $tenant->village_name,
            $tenant->town_name,
            $tenant->state_name,
            $tenant->region_name
        ], function ($part) {
            return is_string($part) && trim($part) !== '';
        });

        $propertyAddress = count($addressParts) ? implode(', ', $addressParts) : null;

        $billings = DB::table('billings')
            ->join('properties', 'billings.property_id', '=', 'properties.id')
            ->where('billings.tenant_id', $tenant->tenant_id)
            ->select(
                'billings.id',
                'billings.rent_amount',
                'billings.rent_due',
                'billings.rent_status',
                'billings.rent_cycle',
                'billings.deposit_paid_amount',
                'billings.advance_paid_amount',
                'billings.property_id',
                'properties.title as property_title'
            )
            ->orderBy('billings.rent_due', 'desc')
            ->get();

        $dueItems = [];
        $billingsById = $billings->keyBy('id');

        foreach ($billings as $bill) {
            if (!in_array($bill->rent_status, ['unpaid', 'overdue', 'pending'], true)) {
                continue;
            }

            $verifiedRentPaid = (float) DB::table('payments')
                ->where('billing_id', $bill->id)
                ->where('status', 'verified')
                ->where(function ($q) {
                    $q->whereNull('remarks')
                      ->orWhere('remarks', 'like', 'payment_type:rent%');
                })
                ->sum('amount_paid');

            $remaining = round(((float) $bill->rent_amount) - $verifiedRentPaid, 2);
            if ($remaining <= 0) {
                continue;
            }

            $dueItems[] = [
                'id' => 'rent_' . $bill->id,
                'type' => 'billing',
                'label' => $bill->rent_cycle . ' Rent',
                'amount' => (float) $bill->rent_amount,
                'amount_due' => $remaining,
                'billing_id' => $bill->id,
                'property_id' => $bill->property_id,
                'due' => $bill->rent_due,
                'rent_status' => $bill->rent_status,
                'billing' => $bill,
            ];
        }

        $latestBilling = $billings->count() ? $billings->first() : null;

        $depositRequired = (float) $tenant->deposit_required;
        $advanceAmount = (float) $tenant->advance_payment_months;

        if ($depositRequired > 0 && $latestBilling) {
            $depositPaid = (float) ($latestBilling->deposit_paid_amount ?? 0);
            $depositRemaining = round($depositRequired - $depositPaid, 2);
            if ($depositRemaining > 0) {
                $dueItems[] = [
                    'id' => 'deposit_' . $tenant->tenant_id,
                    'type' => 'deposit',
                    'label' => 'Security Deposit',
                    'amount' => $depositRequired,
                    'amount_due' => $depositRemaining,
                    'billing_id' => $latestBilling->id,
                    'property_id' => $tenant->property_id,
                    'due' => 'Upon move-in',
                    'rent_status' => 'unpaid',
                ];
            }
        }

        if ($advanceAmount > 0 && $latestBilling) {
            $advanceRequired = round($advanceAmount, 2);
            $advancePaid = (float) ($latestBilling->advance_paid_amount ?? 0);
            $advanceRemaining = round($advanceRequired - $advancePaid, 2);
            if ($advanceRemaining > 0) {
                $dueItems[] = [
                    'id' => 'advance_' . $tenant->tenant_id,
                    'type' => 'advance',
                    'label' => 'Advance Payment (Move-out Notice)',
                    'amount' => $advanceRequired,
                    'amount_due' => $advanceRemaining,
                    'billing_id' => $latestBilling->id,
                    'property_id' => $tenant->property_id,
                    'due' => 'Upon move-in',
                    'rent_status' => 'unpaid',
                ];
            }
        }

        return response()->json([
            'data' => [
                'tenant_status' => $tenant->tenant_status,
                'move_in_date' => $tenant->move_in_date,
                'property_title' => $tenant->property_title,
                'property_type' => $tenant->property_type,
                'property_address' => $propertyAddress,
                'property_id' => $tenant->property_id,
                'deposit_required' => $tenant->deposit_required,
                'advance_payment_months' => $tenant->advance_payment_months,
                'billings' => $billings,
                'due_items' => $dueItems,
            ]
        ], 200);
    }

    public function mockPayments(Request $request)
    {
        $validated = $request->validate([
            'billing_id' => 'required|integer',
            'property_id' => 'required|integer',
            'payment_type' => 'required|string|in:rent,deposit,advance',
            'payment_method' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $tenantId = DB::table('tenants')->where('user_id', Auth::id())->value('id');
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant profile not found.'], 404);
        }

        return DB::transaction(function () use ($validated, $tenantId) {
            $billing = DB::table('billings')
                ->where('id', $validated['billing_id'])
                ->where('tenant_id', $tenantId)
                ->where('property_id', $validated['property_id'])
                ->first();

            if (!$billing) {
                return response()->json(['message' => 'Billing record not found for this tenant.'], 404);
            }

            $property = DB::table('properties')
                ->where('id', $validated['property_id'])
                ->select('deposit_required', 'advance_payment_months')
                ->first();

            if (!$property) {
                return response()->json(['message' => 'Property not found.'], 404);
            }

            $amount = round((float) $validated['amount'], 2);
            if ($validated['payment_type'] === 'rent') {
                if ($billing->rent_status === 'paid') {
                    return response()->json(['message' => 'This billing is already paid.'], 422);
                }

                $verifiedRentPaid = (float) DB::table('payments')
                    ->where('billing_id', $billing->id)
                    ->where('status', 'verified')
                    ->where(function ($q) {
                        $q->whereNull('remarks')
                          ->orWhere('remarks', 'like', 'payment_type:rent%');
                    })
                    ->sum('amount_paid');
                $remaining = round(((float) $billing->rent_amount) - $verifiedRentPaid, 2);
                if ($remaining <= 0) {
                    return response()->json(['message' => 'This billing is already fully covered.'], 422);
                }
                if ($amount > $remaining) {
                    return response()->json(['message' => 'Amount exceeds remaining balance.'], 422);
                }
            } elseif ($validated['payment_type'] === 'deposit') {
                $required = round((float) $property->deposit_required, 2);
                if ($required <= 0) {
                    return response()->json(['message' => 'Payment type not applicable.'], 422);
                }
                $depositPaid = (float) ($billing->deposit_paid_amount ?? 0);
                $remaining = round($required - $depositPaid, 2);
                if ($remaining <= 0) {
                    return response()->json(['message' => 'Security deposit already covered.'], 422);
                }
                if ($amount > $remaining) {
                    return response()->json(['message' => 'Amount exceeds remaining balance.'], 422);
                }
            } elseif ($validated['payment_type'] === 'advance') {
                $required = round((float) $property->advance_payment_months, 2);
                if ($required <= 0) {
                    return response()->json(['message' => 'Payment type not applicable.'], 422);
                }
                $advancePaid = (float) ($billing->advance_paid_amount ?? 0);
                $remaining = round($required - $advancePaid, 2);
                if ($remaining <= 0) {
                    return response()->json(['message' => 'Advance payment already covered.'], 422);
                }
                if ($amount > $remaining) {
                    return response()->json(['message' => 'Amount exceeds remaining balance.'], 422);
                }
            }

            DB::table('payments')->insert([
                'billing_id' => $billing->id,
                'amount_paid' => $amount,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => null,
                'proof' => null,
                'remarks' => 'payment_type:' . $validated['payment_type'],
                'status' => 'verified',
                'date_paid' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($validated['payment_type'] === 'rent') {
                $verifiedRentPaid = (float) DB::table('payments')
                    ->where('billing_id', $billing->id)
                    ->where('status', 'verified')
                    ->where(function ($q) {
                        $q->whereNull('remarks')
                          ->orWhere('remarks', 'like', 'payment_type:rent%');
                    })
                    ->sum('amount_paid');

                if (round(((float) $billing->rent_amount) - $verifiedRentPaid, 2) <= 0) {
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
                        'deposit_paid_amount' => DB::raw('deposit_paid_amount + ' . $amount),
                        'updated_at' => now(),
                    ]);
            } elseif ($validated['payment_type'] === 'advance') {
                DB::table('billings')
                    ->where('id', $billing->id)
                    ->update([
                        'advance_paid_amount' => DB::raw('advance_paid_amount + ' . $amount),
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'message' => 'Payment recorded successfully.',
            ], 201);
        });
    }

    public function submitPayment(Request $request) {
        $validated = $request->validate([
            'billing_id' => 'required|integer',
            'payment_method' => 'required|string|max:50',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_reference' => 'nullable|string|max:100',
            'proof' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $tenantId = DB::table('tenants')->where('user_id', Auth::id())->value('id');
        if (!$tenantId) {
            return response()->json([
                'message' => 'Tenant profile not found.'
            ], 404);
        }

        return DB::transaction(function () use ($validated, $tenantId, $request) {
            $billing = DB::table('billings')
                ->where('id', $validated['billing_id'])
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$billing) {
                return response()->json([
                    'message' => 'Billing record not found for this tenant.'
                ], 404);
            }

            if (!in_array($billing->rent_status, ['unpaid', 'overdue'], true)) {
                return response()->json([
                    'message' => 'This billing cannot accept payment submissions right now.'
                ], 422);
            }

            $hasPendingPayment = DB::table('payments')
                ->where('billing_id', $billing->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingPayment) {
                return response()->json([
                    'message' => 'A pending payment already exists for this billing.'
                ], 409);
            }

            $submittedAmount = round((float) $validated['amount_paid'], 2);
            $expectedAmount = round((float) $billing->rent_amount, 2);
            if ($submittedAmount !== $expectedAmount) {
                return response()->json([
                    'message' => 'Submitted amount must exactly match the billing amount.',
                    'expected_amount' => $expectedAmount,
                ], 422);
            }

            $proofPath = $request->file('proof')->store('proofs', 'public');

            $paymentId = DB::table('payments')->insertGetId([
                'billing_id' => $billing->id,
                'amount_paid' => $submittedAmount,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'proof' => $proofPath,
                'remarks' => $validated['remarks'] ?? null,
                'status' => 'pending',
                'date_paid' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('billings')
                ->where('id', $billing->id)
                ->update([
                    'rent_status' => 'pending',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Payment submitted successfully.',
                'payment_id' => $paymentId,
            ], 201);
        });
    }
}
