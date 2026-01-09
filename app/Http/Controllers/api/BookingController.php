<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\TenantsRequest;
use Illuminate\Support\Facades\Auth;

use function Laravel\Prompts\select;

class BookingController extends Controller
{
    public function submitBookingRequest(Request $request)
    {
        try {
            // Using $request->all() or specific validation logic
            $propertyId = $request->property_id;
            $userId = Auth::id();

            // 1. Normalize stay_months if custom
            $stayDuration = $request->stay_months;
            if ($stayDuration === "custom") {
                $stayDuration = $request->custom_months;
            }

            // 2. Owners cannot book properties
            $isOwner = DB::table("owners")->where("user_id", $userId)->exists();
            if ($isOwner) {
                return response()->json(["error" => "Owners cannot book properties."], 403);
            }

            // 3. Check for existing "active" engagement
            // This prevents users from booking if they are already waiting or already live there
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

            // 4. Check Property Existence and Type
            $property = DB::table('properties')
                ->join("property_types", "properties.property_type_id", "=", "property_types.id")
                ->select("properties.*", "property_types.type_name")
                ->where('properties.id', $propertyId)
                ->first();

            if (!$property) {
                return response()->json(["error" => "Property Not Found"], 404);
            }

            // 5. Validation Logic based on Property Type
            if (empty($request->agreement)) {
                return response()->json(["error" => "Agreement checkbox must be accepted"], 422);
            }

            // Validation for Boarding Houses
            if ($property->type_name === "Boarding House") {
                if (empty($stayDuration) || intval($stayDuration) <= 0) {
                    return response()->json(["error" => "Invalid stay duration"], 422);
                }
            }

            // Validation for Apartments/Condos/Houses
            if (in_array($property->type_name, ["Apartment", "Condo", "House", "Commercial Space"])) {
                if (empty($request->lease_duration) || intval($request->lease_duration) <= 0) {
                    return response()->json(["error" => "Invalid lease duration"], 422);
                }
                if (empty($request->occupant_num) || intval($request->occupant_num) <= 0) {
                    $label = ($property->type_name === "Commercial Space") ? "area needed" : "number of occupants";
                    return response()->json(["error" => "Invalid " . $label], 422);
                }
            }

            // Date validation
            if (empty($request->move_in_date) || strtotime($request->move_in_date) < strtotime(date('Y-m-d'))) {
                return response()->json(["error" => "Move-in date cannot be in the past"], 422);
            }

            // 6. Final Step: Insert the Booking
            DB::table('bookings')->insert([
                'user_id'         => $userId,
                'property_id'     => $propertyId,
                'status'          => 'pending',
                'stay_duration'   => $stayDuration,
                'occupants_num'   => $request->occupant_num,
                'move_in_date'    => $request->move_in_date,
                'lease_duration'  => $request->lease_duration,
                'room_preference' => $request->room_preference,
                'notes'           => $request->notes,
                'agreement'       => $request->agreement,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            return response()->json(["message" => "Booking Request Submitted Successfully"], 201);

        } catch (\Exception $e) {
            return response()->json([
                "error" => "Server Booking Error",
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function getPendingUserBookings() {
        try {
            //code...

            $owner = DB::table("owners")
            ->where("user_id", Auth::id())
            ->first();



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

            if($bookings->isEmpty()){
                return response()->json([
                    "message" => "No Bookings Found",
                    "data" => $bookings
                ]);
            }

            return response()->json([
                "message" => "Bookings Retrived Successfully",
                "data" => $bookings
            ]);



        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "message" => "Server Booking Retrival Error",
                "error" => $e->getMessage()
            ]);
        }
    }


    //!!! Actions

    public function approveBooking(int $booking_id){
        try {

            // Get booking by ID
            $approveBooking = DB::table("bookings")
                ->where("id", $booking_id)
                ->first();

            // Booking not found
            if (!$approveBooking) {
                return response()->json([
                    "error" => "Booking Not Found"
                ], 404);
            }

            // Booking must be pending
            if ($approveBooking->status !== "pending") {
                return response()->json([
                    "error" => "Booking is not pending"
                ], 400);
            }

            // Approve booking
            DB::table("bookings")
                ->where("id", $booking_id)
                ->update([
                    "status" => "approved",
                    "updated_at" => now()
                ]);

            $today = \Carbon\Carbon::today();
            $moveIn = \Carbon\Carbon::parse($approveBooking->move_in_date);

            if ($moveIn->lte($today)) {
                // Move-in date is today or in the past → activate tenant
                $tenantStatus = 'active';
                $billingStatus = 'unpaid';
            } else {
                // Move-in date is in the future → keep inactive / pending
                $tenantStatus = 'inactive';
                $billingStatus = 'pending';
            }

            // Insert into tenants table
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

            $rent_due = $approveBooking->move_in_date; // start from move-in
            switch($propertyInfo->payment_frequency) {
                case 'monthly':
                    $rent_due = \Carbon\Carbon::parse($approveBooking->move_in_date)->addMonth();
                    break;
                case 'quarterly':
                    $rent_due = \Carbon\Carbon::parse($approveBooking->move_in_date)->addMonths(3);
                    break;
                case 'yearly':
                    $rent_due = \Carbon\Carbon::parse($approveBooking->move_in_date)->addYear();
                    break;
                case 'weekly':
                    $rent_due = \Carbon\Carbon::parse($approveBooking->move_in_date)->addWeek();
                    break;
                case 'pernight':
                case 'daily':
                    $rent_due = \Carbon\Carbon::parse($approveBooking->move_in_date)->addDay();
                    break;
                default:
                    $rent_due = \Carbon\Carbon::parse($approveBooking->move_in_date);
                    break;
            }
            DB::table("billings")->insertGetId([
                "tenant_id" => $tenant,
                "property_id" => $approveBooking->property_id,
                "rent_amount" => $propertyInfo->price,
                "rent_cycle" => $propertyInfo->payment_frequency,
                "rent_start" => $approveBooking->move_in_date,
                "rent_due" => $rent_due,
                "rent_status" => $billingStatus,
                "created_at" => now(),
                "updated_at" => now(),
            ]);

            $userId = DB::table("tenants")->where("id",$tenant)->value("user_id");
            DB::table("users")->where("id", $userId)->update([
                "role" => "tenants"
            ]);
            // Delete booking after approval
            // DB::table("bookings")->where("id", $booking_id)->delete();

            return response()->json([
                "message" => "Booking approved successfully"
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "message" => "Server Booking Approval Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }

}
