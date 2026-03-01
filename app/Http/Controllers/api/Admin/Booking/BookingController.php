<?php

namespace App\Http\Controllers\Api\Admin\Booking;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $status = strtolower(trim((string) $request->query('status', 'all')));
            $search = trim((string) $request->query('search', ''));
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            $allowedStatuses = ['all', 'pending', 'approved', 'rejected'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'all';
            }

            $query = DB::table('bookings')
                ->join('users as tenant_user', 'bookings.user_id', '=', 'tenant_user.id')
                ->join('properties', 'bookings.property_id', '=', 'properties.id')
                ->join('owners', 'properties.owner_id', '=', 'owners.id')
                ->join('users as owner_user', 'owners.user_id', '=', 'owner_user.id')
                ->select(
                    'bookings.id',
                    'bookings.status',
                    'bookings.move_in_date',
                    'bookings.occupants_num',
                    'bookings.lease_duration',
                    'bookings.rejection_reason',
                    'bookings.created_at',
                    'bookings.updated_at',
                    'properties.id as property_id',
                    'properties.title as property_title',
                    DB::raw("CONCAT(tenant_user.first_name, ' ', tenant_user.last_name) as tenant_name"),
                    'tenant_user.email as tenant_email',
                    DB::raw("CONCAT(owner_user.first_name, ' ', owner_user.last_name) as owner_name"),
                    'owner_user.email as owner_email'
                )
                ->orderByDesc('bookings.created_at');

            if ($status !== 'all') {
                $query->where('bookings.status', $status);
            }

            if ($search !== '') {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('properties.title', 'like', '%' . $search . '%')
                        ->orWhere('tenant_user.first_name', 'like', '%' . $search . '%')
                        ->orWhere('tenant_user.last_name', 'like', '%' . $search . '%')
                        ->orWhere('owner_user.first_name', 'like', '%' . $search . '%')
                        ->orWhere('owner_user.last_name', 'like', '%' . $search . '%')
                        ->orWhere('tenant_user.email', 'like', '%' . $search . '%')
                        ->orWhere('owner_user.email', 'like', '%' . $search . '%');
                });
            }

            if (!empty($dateFrom)) {
                $query->whereDate('bookings.created_at', '>=', $dateFrom);
            }

            if (!empty($dateTo)) {
                $query->whereDate('bookings.created_at', '<=', $dateTo);
            }

            $bookings = $query->get();

            return response()->json([
                'message' => 'Admin bookings retrieved successfully',
                'data' => $bookings,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $booking = DB::table('bookings')
                ->join('users as tenant_user', 'bookings.user_id', '=', 'tenant_user.id')
                ->join('properties', 'bookings.property_id', '=', 'properties.id')
                ->join('owners', 'properties.owner_id', '=', 'owners.id')
                ->join('users as owner_user', 'owners.user_id', '=', 'owner_user.id')
                ->select(
                    'bookings.*',
                    'properties.title as property_title',
                    'properties.price as property_price',
                    'properties.payment_frequency',
                    DB::raw("CONCAT(tenant_user.first_name, ' ', tenant_user.last_name) as tenant_name"),
                    'tenant_user.email as tenant_email',
                    'tenant_user.phone_number as tenant_phone',
                    DB::raw("CONCAT(owner_user.first_name, ' ', owner_user.last_name) as owner_name"),
                    'owner_user.email as owner_email',
                    DB::raw("CASE WHEN bookings.valid_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', bookings.valid_id_path) ELSE NULL END as valid_id_url")
                )
                ->where('bookings.id', $id)
                ->first();

            if (!$booking) {
                return response()->json([
                    'message' => 'Booking not found',
                ], 404);
            }

            return response()->json([
                'message' => 'Booking details retrieved successfully',
                'data' => $booking,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function forceCancel(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            return DB::transaction(function () use ($id, $validated) {
                $booking = DB::table('bookings')
                    ->where('id', $id)
                    ->first();

                if (!$booking) {
                    return response()->json([
                        'message' => 'Booking not found',
                    ], 404);
                }

                if ($booking->status === 'rejected') {
                    return response()->json([
                        'message' => 'Booking is already rejected.',
                    ], 422);
                }

                $reason = 'Admin force-cancel: ' . trim($validated['reason']);

                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update([
                        'status' => 'rejected',
                        'rejection_reason' => $reason,
                        'updated_at' => now(),
                    ]);

                if ($booking->status === 'approved') {
                    DB::table('tenants')
                        ->where('user_id', $booking->user_id)
                        ->where('property_id', $booking->property_id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'inactive',
                            'updated_at' => now(),
                        ]);

                    $hasAnyActiveTenant = DB::table('tenants')
                        ->where('user_id', $booking->user_id)
                        ->where('status', 'active')
                        ->exists();

                    if (!$hasAnyActiveTenant) {
                        DB::table('users')
                            ->where('id', $booking->user_id)
                            ->where('role', 'tenants')
                            ->update([
                                'role' => 'user',
                                'updated_at' => now(),
                            ]);
                    }
                }

                return response()->json([
                    'message' => 'Booking force-cancelled successfully.',
                ], 200);
            });
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Server Error',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}

