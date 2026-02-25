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

            $stayDuration = $request->stay_months;
            if ($stayDuration === "custom") {
                $stayDuration = $request->custom_months;
            }

            $isOwner = DB::table("owners")->where("user_id", $userId)->exists();
            if ($isOwner) {
                return response()->json(["error" => "Owners cannot book properties."], 403);
            }

            $hasPendingOrApproved = DB::table('bookings')
                ->where('user_id', $userId)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            $isAlreadyTenant = DB::table('tenants')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            if ($hasPendingOrApproved || $isAlreadyTenant) {
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

            if (empty($request->agreement)) {
                return response()->json(["error" => "Agreement checkbox must be accepted"], 422);
            }

            if ($property->type_name === "Boarding House") {
                if (empty($stayDuration) || intval($stayDuration) <= 0) {
                    return response()->json(["error" => "Invalid stay duration"], 422);
                }
            }

            if (in_array($property->type_name, ["Apartment", "Condo", "House", "Commercial Space"], true)) {
                if (empty($request->lease_duration) || intval($request->lease_duration) <= 0) {
                    return response()->json(["error" => "Invalid lease duration"], 422);
                }
                if (empty($request->occupant_num) || intval($request->occupant_num) <= 0) {
                    $label = ($property->type_name === "Commercial Space") ? "area needed" : "number of occupants";
                    return response()->json(["error" => "Invalid " . $label], 422);
                }
            }

            if (empty($request->move_in_date) || strtotime($request->move_in_date) < strtotime(date('Y-m-d'))) {
                return response()->json(["error" => "Move-in date cannot be in the past"], 422);
            }

            $bookingId = DB::table('bookings')->insertGetId([
                'user_id' => $userId,
                'property_id' => $propertyId,
                'status' => 'pending',
                'stay_duration' => $stayDuration,
                'occupants_num' => $request->occupant_num,
                'move_in_date' => $request->move_in_date,
                'lease_duration' => $request->lease_duration,
                'room_preference' => $request->room_preference,
                'notes' => $request->notes,
                'agreement' => $request->agreement,
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

            $bookings = DB::table("bookings")
                ->join("properties", "bookings.property_id", "=", "properties.id")
                ->join("property_types", "properties.property_type_id", "=", "property_types.id")
                ->join("users", "bookings.user_id", "=", "users.id")
                ->select(
                    "bookings.*",
                    "users.first_name",
                    "users.last_name",
                    "properties.id as property_id",
                    "properties.title",
                    "property_types.type_name"
                )
                ->where("bookings.status", "pending")
                ->where("properties.owner_id", $owner->id)
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
}
