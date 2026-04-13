<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function formatOldestWaiting(?string $timestamp): string
    {
        if (!$timestamp) {
            return 'No backlog';
        }

        return 'Oldest ' . Carbon::parse($timestamp)->diffForHumans(now(), [
            'parts' => 2,
            'short' => true,
            'syntax' => Carbon::DIFF_ABSOLUTE,
        ]);
    }

    public function overview()
    {
        try {
            $activeProperties = (int) DB::table('properties')
                ->where('status', 'active')
                ->count();

            $totalUsers = (int) DB::table('users')->count();

            $pendingApprovals = (int) DB::table('properties')
                ->where('status', 'pending')
                ->count();

            $collectionsThisMonth = (float) DB::table('payments')
                ->where('status', 'verified')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount_paid');

            $propertyApprovalsOldest = DB::table('properties')
                ->where('status', 'pending')
                ->min('created_at');

            $ownerVerificationsCount = (int) DB::table('owners')
                ->where('owner_verification_status', 'pending')
                ->count();

            $ownerVerificationsOldest = DB::table('owners')
                ->where('owner_verification_status', 'pending')
                ->min('created_at');

            $paymentVerificationsCount = (int) DB::table('payments')
                ->where('status', 'pending')
                ->count();

            $paymentVerificationsOldest = DB::table('payments')
                ->where('status', 'pending')
                ->min('created_at');

            $unreadInquiriesCount = (int) DB::table('inquiries')
                ->where('status', 'unread')
                ->count();

            $unreadInquiriesOldest = DB::table('inquiries')
                ->where('status', 'unread')
                ->min('created_at');

            $pendingProperties = DB::table('properties')
                ->join('owners', 'properties.owner_id', '=', 'owners.id')
                ->join('users', 'owners.user_id', '=', 'users.id')
                ->where('properties.status', 'pending')
                ->select(
                    'properties.id',
                    'properties.title as name',
                    DB::raw("CONCAT(users.first_name, ' ', users.last_name) as owner"),
                    'properties.created_at'
                )
                ->orderBy('properties.created_at')
                ->limit(10)
                ->get();

            return response()->json([
                'message' => 'Admin dashboard overview retrieved successfully.',
                'data' => [
                    'stats' => [
                        'active_properties' => $activeProperties,
                        'total_users' => $totalUsers,
                        'pending_approvals' => $pendingApprovals,
                        'collections_this_month' => round($collectionsThisMonth, 2),
                    ],
                    'action_queue' => [
                        [
                            'id' => 'property_approvals',
                            'label' => 'Property approvals',
                            'count' => $pendingApprovals,
                            'waiting' => $this->formatOldestWaiting($propertyApprovalsOldest),
                            'route' => '/admin/properties',
                        ],
                        [
                            'id' => 'owner_verifications',
                            'label' => 'Owner verifications',
                            'count' => $ownerVerificationsCount,
                            'waiting' => $this->formatOldestWaiting($ownerVerificationsOldest),
                            'route' => '/admin/owners',
                        ],
                        [
                            'id' => 'payment_verifications',
                            'label' => 'Payment verifications',
                            'count' => $paymentVerificationsCount,
                            'waiting' => $this->formatOldestWaiting($paymentVerificationsOldest),
                            'route' => '/admin/properties',
                        ],
                        [
                            'id' => 'inquiries',
                            'label' => 'Inquiries',
                            'count' => $unreadInquiriesCount,
                            'waiting' => $this->formatOldestWaiting($unreadInquiriesOldest),
                            'route' => '/admin/inquiries',
                        ],
                    ],
                    'pending_properties' => $pendingProperties,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to fetch admin dashboard overview.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
