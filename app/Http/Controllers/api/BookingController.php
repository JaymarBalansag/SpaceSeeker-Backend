<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Observers\Notifications\Logic\NotificationLogicObserver;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected NotificationLogicObserver $notificationLogicObserver
    ) {
    }

    public function submitBookingRequest(Request $request)
    {
        try {
            $propertyId = $request->property_id;
            $userId = Auth::id();

            $user = Auth::user();
            $isOwner = strtolower((string) ($user?->role ?? '')) === 'owner';
            if ($isOwner) {
                return response()->json(["error" => "Owners cannot book properties."], 403);
            }

            $hasPendingBooking = DB::table('bookings')
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->exists();

            $hasApprovedBooking = DB::table('bookings')
                ->where('user_id', $userId)
                ->where('status', 'approved')
                ->exists();

            $isActiveTenant = DB::table('tenants')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            $latestTenantStatus = DB::table('tenants')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('status');

            $isMoveOutTenant = strtolower((string) $latestTenantStatus) === 'move_out';

            if ($hasPendingBooking || $isActiveTenant || ($hasApprovedBooking && !$isMoveOutTenant)) {
                return response()->json(["error" => "You already have a pending application or an active tenancy."], 403);
            }

            $property = DB::table('properties')
                ->join("property_types", "properties.property_type_id", "=", "property_types.id")
                ->select("properties.*", "property_types.type_name")
                ->where('properties.id', $propertyId)
                ->first();

            if (!$property) {
                return response()->json(["error" => "Property Not Found"], 404);
            }

            $ownerBillingCycle = DB::table('subscriptions')
                ->where('owner_id', $property->owner_id)
                ->where('status', 'active')
                ->whereDate('end_date', '>=', now()->toDateString())
                ->orderByDesc('end_date')
                ->orderByDesc('id')
                ->value('billing_cycle');

            if (strtolower((string) $ownerBillingCycle) === 'monthly') {
                return response()->json(["error" => "Booking is not available for this property at the moment."], 403);
            }

            if (empty($request->agreement)) {
                return response()->json(["error" => "Agreement checkbox must be accepted"], 422);
            }

            if (empty($request->move_in_date) || strtotime($request->move_in_date) < strtotime(date('Y-m-d'))) {
                return response()->json(["error" => "Move-in date cannot be in the past"], 422);
            }

            $bookingId = DB::table('bookings')->insertGetId([
                'user_id' => $userId,
                'property_id' => $propertyId,
                'status' => 'pending',
                'stay_duration' => null,
                'occupants_num' => $request->occupant_num,
                'move_in_date' => $request->move_in_date,
                'lease_duration' => $request->lease_duration,
                'room_preference' => $request->room_preference,
                'notes' => $request->notes,
                'agreement' => $request->agreement,
                'valid_id_path' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ownerUserId = DB::table('owners')
                ->where('id', $property->owner_id)
                ->value('user_id');

            if ($ownerUserId) {
                $requesterName = trim((string) DB::table('users')
                    ->where('id', $userId)
                    ->selectRaw("CONCAT(first_name, ' ', last_name) as full_name")
                    ->value('full_name'));

                $payload = $this->notificationLogicObserver->buildBookingPendingPayload(
                    (string) $property->title,
                    $requesterName !== '' ? $requesterName : 'A user',
                    (int) $bookingId
                );

                $this->notificationService->createForUser((int) $ownerUserId, $payload);
            }

            return response()->json(["message" => "Booking Request Submitted Successfully"], 201);
        } catch (\Exception $e) {
            return response()->json([
                "error" => "Server Booking Error",
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function getPendingUserBookings()
    {
        try {
            $owner = DB::table("owners")
                ->where("user_id", Auth::id())
                ->first();

            if (!$owner) {
                return response()->json([
                    "message" => "Owner profile not found",
                    "data" => []
                ], 404);
            }

            $status = trim((string) request()->query("status", "pending"));
            $allowedStatuses = ["pending", "approved", "rejected"];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = "pending";
            }

            $bookings = DB::table("bookings")
                ->join("properties", "bookings.property_id", "=", "properties.id")
                ->join("property_types", "properties.property_type_id", "=", "property_types.id")
                ->join("users", "bookings.user_id", "=", "users.id")
                ->select(
                    "bookings.*",
                    "users.first_name",
                    "users.last_name",
                    "users.email",
                    "users.phone_number",
                    "properties.id as property_id",
                    "properties.title",
                    "property_types.type_name",
                    DB::raw("CASE WHEN bookings.valid_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', bookings.valid_id_path) ELSE NULL END as valid_id_url"),
                    DB::raw("CASE WHEN users.user_valid_govt_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_valid_govt_id_path) ELSE NULL END as user_valid_govt_id_url")
                )
                ->where("properties.owner_id", $owner->id)
                ->where("bookings.status", $status)
                ->orderByDesc("bookings.created_at")
                ->get();

            if ($bookings->isEmpty()) {
                return response()->json([
                    "message" => "No Bookings Found",
                    "data" => $bookings
                ]);
            }

            return response()->json([
                "message" => "Bookings Retrieved Successfully",
                "data" => $bookings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Booking Retrieval Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function getMyBookings(Request $request)
    {
        try {
            $userId = Auth::id();
            $status = trim((string) $request->query("status", "all"));
            $allowedStatuses = ["all", "pending", "approved", "rejected"];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = "all";
            }

            $query = DB::table("bookings")
                ->join("properties", "bookings.property_id", "=", "properties.id")
                ->join("property_types", "properties.property_type_id", "=", "property_types.id")
                ->join("owners", "properties.owner_id", "=", "owners.id")
                ->join("users as owner_user", "owners.user_id", "=", "owner_user.id")
                ->where("bookings.user_id", $userId)
                ->select(
                    "bookings.id",
                    "bookings.status",
                    "bookings.move_in_date",
                    "bookings.occupants_num",
                    "bookings.lease_duration",
                    "bookings.notes",
                    "bookings.rejection_reason",
                    "bookings.created_at",
                    "properties.id as property_id",
                    "properties.title",
                    "property_types.type_name",
                    DB::raw("CONCAT(owner_user.first_name, ' ', owner_user.last_name) as owner_name"),
                    DB::raw("CASE WHEN bookings.valid_id_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', bookings.valid_id_path) ELSE NULL END as valid_id_url")
                )
                ->orderByDesc("bookings.created_at");

            if ($status !== "all") {
                $query->where("bookings.status", $status);
            }

            $bookings = $query->get();

            return response()->json([
                "message" => "My bookings retrieved successfully",
                "data" => $bookings
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server booking retrieval error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function approveBooking(int $booking_id)
    {
        try {
            $ownerId = DB::table("owners")
                ->where("user_id", Auth::id())
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    "error" => "Owner profile not found"
                ], 404);
            }

            return DB::transaction(function () use ($booking_id, $ownerId) {
                $approveBooking = DB::table("bookings")
                    ->join("properties", "bookings.property_id", "=", "properties.id")
                    ->select("bookings.*", "properties.owner_id")
                    ->where("bookings.id", $booking_id)
                    ->where("properties.owner_id", $ownerId)
                    ->first();

                if (!$approveBooking) {
                    return response()->json([
                        "error" => "Booking not found for this owner"
                    ], 404);
                }

                if ($approveBooking->status !== "pending") {
                    return response()->json([
                        "error" => "Booking is not pending"
                    ], 400);
                }

                DB::table("bookings")
                    ->where("id", $booking_id)
                    ->update([
                        "status" => "approved",
                        "updated_at" => now()
                    ]);

                $today = \Carbon\Carbon::today();
                $moveIn = \Carbon\Carbon::parse($approveBooking->move_in_date);
                $tenantStatus = $moveIn->lte($today) ? 'active' : 'inactive';
                $billingStatus = $moveIn->lte($today) ? 'unpaid' : 'pending';

                $existingTenant = DB::table("tenants")
                    ->where("user_id", $approveBooking->user_id)
                    ->where("property_id", $approveBooking->property_id)
                    ->where("status", "inactive")
                    ->when($approveBooking->move_in_date, function ($q) use ($approveBooking) {
                        $q->whereDate("move_in_date", $approveBooking->move_in_date);
                    }, function ($q) {
                        $q->whereNull("move_in_date");
                    })
                    ->orderByDesc("updated_at")
                    ->first();

                if ($existingTenant) {
                    DB::table("tenants")
                        ->where("id", $existingTenant->id)
                        ->update([
                            "property_id" => $approveBooking->property_id,
                            "updated_at" => now(),
                            "stay_duration" => $approveBooking->stay_duration ?? null,
                            "move_in_date" => $approveBooking->move_in_date ?? null,
                            "occupants_num" => $approveBooking->occupants_num ?? null,
                            "lease_duration" => $approveBooking->lease_duration ?? null,
                            "room_preference" => $approveBooking->room_preference ?? null,
                            "notes" => $approveBooking->notes ?? null,
                            "status" => $tenantStatus,
                            "agreement" => $approveBooking->agreement ?? null,
                            "ended_at" => null,
                        ]);

                    $tenant = $existingTenant->id;
                } else {
                    $tenant = DB::table("tenants")->insertGetId([
                        "user_id" => $approveBooking->user_id,
                        "property_id" => $approveBooking->property_id,
                        "created_at" => now(),
                        "updated_at" => now(),
                        "stay_duration" => $approveBooking->stay_duration ?? null,
                        "move_in_date" => $approveBooking->move_in_date ?? null,
                        "occupants_num" => $approveBooking->occupants_num ?? null,
                        "lease_duration" => $approveBooking->lease_duration ?? null,
                        "room_preference" => $approveBooking->room_preference ?? null,
                        "notes" => $approveBooking->notes ?? null,
                        "status" => $tenantStatus,
                        "agreement" => $approveBooking->agreement ?? null,
                    ]);
                }

                $propertyInfo = DB::table("properties")->where("id", "=", $approveBooking->property_id)->first();
                if (!$propertyInfo) {
                    throw new \Exception("Property not found");
                }

                $rentDue = \Carbon\Carbon::parse($approveBooking->move_in_date);
                switch ($propertyInfo->payment_frequency) {
                    case 'monthly':
                        $rentDue->addMonth();
                        break;
                    case 'quarterly':
                        $rentDue->addMonths(3);
                        break;
                    case 'yearly':
                        $rentDue->addYear();
                        break;
                    case 'weekly':
                        $rentDue->addWeek();
                        break;
                    case 'pernight':
                    case 'daily':
                        $rentDue->addDay();
                        break;
                    default:
                        break;
                }

                DB::table("billings")->insertGetId([
                    "tenant_id" => $tenant,
                    "property_id" => $approveBooking->property_id,
                    "rent_amount" => $propertyInfo->price,
                    "rent_cycle" => $propertyInfo->payment_frequency,
                    "rent_start" => $approveBooking->move_in_date,
                    "rent_due" => $rentDue->toDateString(),
                    "rent_status" => $billingStatus,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);

                $userId = DB::table("tenants")->where("id", $tenant)->value("user_id");
                DB::table("users")->where("id", $userId)->update([
                    "role" => "tenants"
                ]);

                return response()->json([
                    "message" => "Booking approved successfully"
                ], 200);
            });
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Booking Approval Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function rejectBooking(Request $request, int $booking_id)
    {
        try {
            $ownerId = DB::table("owners")
                ->where("user_id", Auth::id())
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    "error" => "Owner profile not found"
                ], 404);
            }

            $validated = $request->validate([
                "reason" => "required|string|max:500"
            ]);

            $booking = DB::table("bookings")
                ->join("properties", "bookings.property_id", "=", "properties.id")
                ->select("bookings.*", "properties.owner_id")
                ->where("bookings.id", $booking_id)
                ->where("properties.owner_id", $ownerId)
                ->first();

            if (!$booking) {
                return response()->json([
                    "error" => "Booking not found for this owner"
                ], 404);
            }

            if ($booking->status !== "pending") {
                return response()->json([
                    "error" => "Only pending bookings can be rejected"
                ], 422);
            }

            DB::table("bookings")
                ->where("id", $booking_id)
                ->update([
                    "status" => "rejected",
                    "rejection_reason" => trim($validated["reason"]),
                    "updated_at" => now()
                ]);

            return response()->json([
                "message" => "Booking rejected successfully"
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Booking Rejection Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
