<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function getBillings(Request $request)
    {
        try {
            $loggedInUser = Auth::id(); // logged-in user
            $ownerId = DB::table("owners")
            ->select("id")
            ->where("user_id", "=", $loggedInUser)
            ->first();
            $status = $request->query('status', 'all'); // default = all
            $perPage = 10;

            $query = DB::table('billings')
                ->join('properties', 'billings.property_id', '=', 'properties.id')
                ->join('tenants', 'billings.tenant_id', '=', 'tenants.id')
                ->join('users', 'tenants.user_id', '=', 'users.id')
                ->where('properties.owner_id', $ownerId->id)
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

    public function verifyPayment($id){
        try {
            return DB::transaction(function () use ($id) {
                // 1. Get the payment
                $payment = DB::table('payments')->where('id', $id)->first();
                if (!$payment) throw new \Exception("Payment not found");

                // 2. Get billing, tenant, and user info in one join
                $billinginfo = DB::table("billings")
                    ->join("tenants", "billings.tenant_id", "=", "tenants.id")
                    ->join("users", "tenants.user_id", "=", "users.id")
                    ->join("properties", "billings.property_id", "=", "properties.id")
                    ->select(
                        "billings.*",
                        "properties.payment_frequency",
                        "users.first_name", 
                        "users.last_name", 
                        "users.email"
                    )
                    ->where("billings.id", "=", $payment->billing_id)
                    ->first();

                if (!$billinginfo) throw new \Exception("Billing information not found");

                $fullName = $billinginfo->first_name . " " . $billinginfo->last_name;

                // 3. Update current payment
                DB::table('payments')->where('id', $id)->update([
                    'status' => 'verified',
                    'updated_at' => now()
                ]);

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

                // 6. Create the NEXT billing
                DB::table("billings")->insert([
                    'tenant_id' => $billinginfo->tenant_id, 
                    'property_id' => $billinginfo->property_id,
                    'tenant_name_snapshot' => $fullName,
                    'tenant_email_snapshot' => $billinginfo->email,
                    'rent_amount' => $billinginfo->rent_amount,
                    'rent_cycle' => $billinginfo->rent_cycle,
                    'rent_start' => $nextStart->toDateString(), // Use string format
                    'rent_due' => $nextDue->toDateString(),     // Use string format
                    'rent_status' => 'unpaid', 
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                return response()->json(['message' => 'Payment verified and next billing created']);
            });
        } catch (\Throwable $th) {
            return response()->json(["error" => $th->getMessage()], 500);
        }
    }

}
