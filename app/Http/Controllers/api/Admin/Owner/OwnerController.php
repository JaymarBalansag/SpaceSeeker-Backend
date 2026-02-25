<?php

namespace App\Http\Controllers\Api\Admin\Owner;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class OwnerController extends Controller
{
    public function getAllOwner()
    {
        try {
            $owners = DB::table('owners')
                ->join('users', 'users.id', '=', 'owners.user_id')
                ->leftJoin('subscriptions', 'subscriptions.owner_id', '=', 'owners.id')
                ->select(
                    'owners.id',
                    'owners.user_id',
                    'owners.owner_verification_status as verification_status',
                    'owners.owner_verified_at',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.created_at',
                    DB::raw("MAX(subscriptions.status) as subscription_status")
                )
                ->groupBy(
                    'owners.id',
                    'owners.user_id',
                    'owners.owner_verification_status',
                    'owners.owner_verified_at',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.created_at'
                )
                ->orderByDesc('users.created_at')
                ->get();

            if ($owners->isEmpty()) {
                return response()->json([
                    'message' => 'No owners found',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'message' => 'Owners retrieved successfully',
                'data' => $owners,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOwnerDetails(int $ownerId)
    {
        try {
            $owner = DB::table('owners')
                ->join('users', 'users.id', '=', 'owners.user_id')
                ->select(
                    'owners.id',
                    'owners.user_id',
                    'owners.phone_number',
                    'owners.paymentType',
                    'owners.business_permit',
                    'owners.valid_govt_id',
                    'owners.owner_verification_status as verification_status',
                    'owners.owner_verified_at',
                    'owners.owner_verification_rejected_reason',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.created_at',
                    DB::raw("CASE WHEN owners.business_permit IS NOT NULL THEN CONCAT('" . asset('storage') . "/', owners.business_permit) ELSE NULL END as business_permit_url"),
                    DB::raw("CASE WHEN owners.valid_govt_id IS NOT NULL THEN CONCAT('" . asset('storage') . "/', owners.valid_govt_id) ELSE NULL END as valid_id_url")
                )
                ->where('owners.id', $ownerId)
                ->first();

            if (!$owner) {
                return response()->json([
                    'message' => 'Owner not found',
                ], 404);
            }

            return response()->json([
                'message' => 'Owner details retrieved successfully',
                'data' => $owner,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateOwnerVerification(Request $request, int $ownerId)
    {
        $validated = $request->validate([
            'verification_status' => 'required|in:verified,rejected,pending,unverified',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $owner = DB::table('owners')->where('id', $ownerId)->first();
            if (!$owner) {
                return response()->json([
                    'message' => 'Owner not found',
                ], 404);
            }

            $status = $validated['verification_status'];
            $reason = $validated['reason'] ?? null;

            $update = [
                'owner_verification_status' => $status,
                'updated_at' => now(),
            ];

            if ($status === 'verified') {
                $update['owner_verified_at'] = now();
                $update['owner_verification_rejected_reason'] = null;
            } elseif ($status === 'rejected') {
                $update['owner_verified_at'] = null;
                $update['owner_verification_rejected_reason'] = $reason;
            } else {
                $update['owner_verified_at'] = null;
                $update['owner_verification_rejected_reason'] = null;
            }

            DB::table('owners')
                ->where('id', $ownerId)
                ->update($update);

            return response()->json([
                'message' => 'Owner verification status updated successfully',
                'data' => [
                    'owner_id' => $ownerId,
                    'verification_status' => $status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActiveOwner()
    {
        try {
            $owners = DB::table('owners')
                ->join('users', 'users.id', '=', 'owners.user_id')
                ->where('owners.status', 'active')
                ->select(
                    'owners.id',
                    'owners.user_id',
                    'owners.owner_verification_status as verification_status',
                    'owners.owner_verified_at',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.created_at'
                )
                ->orderByDesc('users.created_at')
                ->get();

            return response()->json([
                'message' => 'Active owners retrieved successfully',
                'data' => $owners,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getInactiveOwner()
    {
        try {
            $owners = DB::table('owners')
                ->join('users', 'users.id', '=', 'owners.user_id')
                ->whereIn('owners.status', ['inactive', 'failed'])
                ->select(
                    'owners.id',
                    'owners.user_id',
                    'owners.owner_verification_status as verification_status',
                    'owners.owner_verified_at',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.created_at'
                )
                ->orderByDesc('users.created_at')
                ->get();

            return response()->json([
                'message' => 'Inactive owners retrieved successfully',
                'data' => $owners,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
