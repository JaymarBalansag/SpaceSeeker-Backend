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
    public function submitBookingRequest(BookingRequest $payload ) {
        try {
            $validated = $payload->validated();
            $propertyid = $validated["property_id"];

            // Normalize stay_months if custom
            if(isset($validated["stay_months"]) && $validated["stay_months"] === "custom"){
                $validated["stay_months"] = $validated["custom_months"];
            }

            $userId = Auth::id();

            // Owners cannot book
            $isOwner = DB::table("owners")->where("user_id", $userId)->first();
            if($isOwner) {
                return response()->json([
                    "error" => "Owners cannot book properties"
                ], 403);
            }

            // Property existence
            $property = DB::table('properties')
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select("properties.*", "property_types.*")
            ->where('properties.id', $propertyid)
            ->first();
            if(!$property) {
                return response()->json([
                    "error" => "Property Not Found"
                ], 404);
            }

            // Duplicate booking check
            $existingBooking = DB::table('bookings')
                ->where('user_id', $userId)
                ->where('property_id', $propertyid)
                ->first();
            if($existingBooking) {
                return response()->json([
                    "message" => "Booking Already Exists"
                ], 400);
            }

            // 🔒 Double Validation (same as frontend)
            if(empty($validated["agreement"])) {
                return response()->json([
                    "error" => "Agreement checkbox must be accepted"
                ], 422);
            }

            if($property->type_name === "Boarding House") {
                if(empty($validated["stay_months"]) || intval($validated["stay_months"]) <= 0) {
                    return response()->json(["error" => "Invalid stay duration"], 422);
                }
                if(empty($validated["move_in_date"]) || strtotime($validated["move_in_date"]) < strtotime(date('Y-m-d'))) {
                    return response()->json(["error" => "Invalid move-in date"], 422);
                }
            }

            if(in_array($property->type_name, ["Apartment", "Condo", "House"])) {
                if(empty($validated["lease_duration"]) || intval($validated["lease_duration"]) <= 0) {
                    return response()->json(["error" => "Invalid lease duration"], 422);
                }
                if(empty($validated["move_in_date"]) || strtotime($validated["move_in_date"]) < strtotime(date('Y-m-d'))) {
                    return response()->json(["error" => "Invalid move-in date"], 422);
                }
                if(empty($validated["occupant_num"]) || intval($validated["occupant_num"]) <= 0) {
                    return response()->json(["error" => "Invalid number of occupants"], 422);
                }
            }

            if($property->type_name === "Commercial Space") {
                if(empty($validated["lease_duration"]) || intval($validated["lease_duration"]) <= 0) {
                    return response()->json(["error" => "Invalid lease duration"], 422);
                }
                if(empty($validated["move_in_date"]) || strtotime($validated["move_in_date"]) < strtotime(date('Y-m-d'))) {
                    return response()->json(["error" => "Invalid move-in date"], 422);
                }
                if(empty($validated["occupant_num"]) || intval($validated["occupant_num"]) <= 0) {
                    return response()->json(["error" => "Invalid area needed"], 422);
                }
            }

            // ✅ Passed validation, insert booking
            DB::table('bookings')->insert([
                'user_id' => $userId,
                'property_id' => $propertyid,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
                "stay_duration" => $validated["stay_months"] ?? null,
                "occupants_num" => $validated["occupant_num"] ?? null,
                "move_in_date" => $validated["move_in_date"] ?? null,
                "lease_duration" => $validated["lease_duration"] ?? null,
                "room_preference" => $validated["room_preference"] ?? null,
                "notes" => $validated["notes"] ?? null,
                "agreement" => $validated["agreement"] ?? null,
            ]);

            return response()->json([
                "message" => "Booking Request Submitted Successfully"
            ], 201);

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

            // Insert into tenants table
            DB::table("tenants")->insert([
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
                "agreement" => $approveBooking->agreement ?? null,
            ]);

            // Delete booking after approval
            DB::table("bookings")->where("id", $booking_id)->delete();

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
