<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    public function submitBookingRequest(BookingRequest $payload ) {
        try {
            //code...

            $validated = $payload->validated();
            $propertyid = $validated["property_id"];

            if($validated["stay_months"] == "custom"){
                $validated["stay_months"] = $validated["custom_months"];
            }

            $userId = Auth::id();

            $isOwner = DB::table("owners")
            ->where("user_id", $userId)
            ->first();

            if($isOwner) {
                return response()->json([
                    "error" => "Owners cannot book properties"
                ], 403);
            }

            $property = DB::table('properties')->where('id', $propertyid)->first();
            if(!$property) {
                return response()->json([
                    "error" => "Property Not Found"
                ], 404);
            }

            $existingBooking = DB::table('bookings')
                ->where('user_id', $userId)
                ->where('property_id', $propertyid)
                ->first();

            if($existingBooking) {
                return response()->json([
                    "message" => "Booking Already Exists"
                ], 400);
            }

            DB::table('bookings')->insert([
                'user_id' => $userId,
                'property_id' => $propertyid,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),

                // The Details
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
            //throw $th;
            return response()->json([
                "error" => "Server Booking Error",
                "message" => $e->getMessage()
            ]);
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
            ->join("users", "bookings.user_id", "=", "users.id")
            ->select(
                "bookings.*",
                "users.first_name",
                "users.last_name",
                "properties.title",

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

    public function approveBooking(int $booking) {
        try {
            //Get Booking Details By booking ID
            $approveBooking = DB::table("bookings")
            ->where("bookings.id", $booking)
            ->first();

            // Check if booking is found and pending
            if($approveBooking->status != "pending" && $approveBooking) {
                return response()->json([
                    "error" => "Only Pending Bookings can be Approved"
                ], 400);

            }

            // TODO:In The Future:
            // TODO: validate if property capacity is full,  if owner is active, payment validations

            $approveBookingRequest = DB::table("bookings")
            ->where("bookings.id", $booking)
            ->update([
                "status" => "approved",
                "updated_at" => now()
            ]); 

            $addedTenants = DB::table("tenants")
            ->insert([
                "user_id" => $approveBooking->user_id,
                "property_id" => $approveBooking->property_id,
                "created_at" => now(),
                "updated_at" => now()
            ]);


        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "message" => "Server Booking Approval Error",
                "error" => $e->getMessage()
            ]);
        }
    }
}
