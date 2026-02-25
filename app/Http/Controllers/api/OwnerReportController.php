<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OwnerReportController extends Controller
{
    private function resolveOwnerId(): ?int
    {
        $ownerId = DB::table('owners')->where('user_id', Auth::id())->value('id');
        return $ownerId ? (int) $ownerId : null;
    }

    public function tenantSummary(Request $request)
    {
        try {
            $ownerId = $this->resolveOwnerId();
            if (!$ownerId) {
                return response()->json(['message' => 'Owner profile not found.'], 404);
            }

            $propertyId = $request->query('property_id');
            $status = $request->query('status');

            $query = DB::table('tenants')
                ->join('properties', 'tenants.property_id', '=', 'properties.id')
                ->join('users', 'tenants.user_id', '=', 'users.id')
                ->where('properties.owner_id', $ownerId)
                ->select(
                    'tenants.id',
                    'tenants.property_id',
                    'tenants.status',
                    'tenants.move_in_date',
                    'tenants.stay_duration',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'properties.title as property_title'
                );

            if (!empty($propertyId)) {
                $query->where('tenants.property_id', (int) $propertyId);
            }
            if (!empty($status)) {
                $query->where('tenants.status', $status);
            }

            $tenants = $query->orderByDesc('tenants.created_at')->get();

            $counts = [
                'total' => $tenants->count(),
                'active' => $tenants->where('status', 'active')->count(),
                'inactive' => $tenants->where('status', 'inactive')->count(),
            ];

            $byProperty = DB::table('tenants')
                ->join('properties', 'tenants.property_id', '=', 'properties.id')
                ->where('properties.owner_id', $ownerId)
                ->select('properties.id as property_id', 'properties.title as property_title', DB::raw('COUNT(tenants.id) as tenant_count'))
                ->groupBy('properties.id', 'properties.title')
                ->orderByDesc('tenant_count')
                ->get();

            return response()->json([
                'message' => 'Tenant summary retrieved successfully.',
                'data' => [
                    'counts' => $counts,
                    'by_property' => $byProperty,
                    'tenants' => $tenants,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to retrieve tenant summary.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function bookingLogs(Request $request)
    {
        try {
            $ownerId = $this->resolveOwnerId();
            if (!$ownerId) {
                return response()->json(['message' => 'Owner profile not found.'], 404);
            }

            $status = $request->query('status');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');
            $perPage = (int) $request->query('per_page', 10);
            $perPage = max(1, min(50, $perPage));

            $query = DB::table('bookings')
                ->join('properties', 'bookings.property_id', '=', 'properties.id')
                ->join('users', 'bookings.user_id', '=', 'users.id')
                ->where('properties.owner_id', $ownerId)
                ->select(
                    'bookings.id',
                    'bookings.status',
                    'bookings.move_in_date',
                    'bookings.occupants_num',
                    'bookings.lease_duration',
                    'bookings.stay_duration',
                    'bookings.created_at',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'properties.id as property_id',
                    'properties.title as property_title'
                )
                ->orderByDesc('bookings.created_at');

            if (!empty($status)) {
                $query->where('bookings.status', $status);
            }
            if (!empty($dateFrom)) {
                $query->whereDate('bookings.created_at', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $query->whereDate('bookings.created_at', '<=', $dateTo);
            }

            $logs = $query->paginate($perPage);

            return response()->json([
                'message' => 'Booking logs retrieved successfully.',
                'data' => $logs,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to retrieve booking logs.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function paymentAnalytics(Request $request)
    {
        try {
            $ownerId = $this->resolveOwnerId();
            if (!$ownerId) {
                return response()->json(['message' => 'Owner profile not found.'], 404);
            }

            $base = DB::table('payments')
                ->join('billings', 'payments.billing_id', '=', 'billings.id')
                ->join('properties', 'billings.property_id', '=', 'properties.id')
                ->where('properties.owner_id', $ownerId);

            $totals = [
                'verified_amount_total' => (float) (clone $base)->where('payments.status', 'verified')->sum('payments.amount_paid'),
                'verified_amount_this_month' => (float) (clone $base)
                    ->where('payments.status', 'verified')
                    ->whereMonth('payments.created_at', now()->month)
                    ->whereYear('payments.created_at', now()->year)
                    ->sum('payments.amount_paid'),
                'pending_count' => (int) (clone $base)->where('payments.status', 'pending')->count(),
                'verified_count' => (int) (clone $base)->where('payments.status', 'verified')->count(),
                'rejected_count' => (int) (clone $base)->where('payments.status', 'rejected')->count(),
            ];

            $monthly = (clone $base)
                ->select(
                    DB::raw('DATE_FORMAT(payments.created_at, "%Y-%m") as month'),
                    DB::raw('SUM(CASE WHEN payments.status = "verified" THEN payments.amount_paid ELSE 0 END) as verified_amount'),
                    DB::raw('COUNT(payments.id) as total_payments')
                )
                ->where('payments.created_at', '>=', now()->subMonths(5)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return response()->json([
                'message' => 'Payment analytics retrieved successfully.',
                'data' => [
                    'totals' => $totals,
                    'monthly' => $monthly,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to retrieve payment analytics.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
