<?php

namespace App\Http\Controllers\Api\Admin\Property;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class PropertyController extends Controller
{

    // Show property when admin click the show more details
    public function ShowProperties($id){

        try {
            // Validate that the ID is a positive integer
            if (!is_numeric($id) || intval($id) <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid property ID provided.'
                ], 400);
            }
            $id = intval($id); // Ensure ID is an integer

            // check if property ID exists
            $checkedId = DB::table("properties")->where("id", "=", $id)->firstOrFail();
            if(!$checkedId){
                return response()->json([
                    'status' => 'error',
                    'message' => 'Property ID not found.'
                ], 404);
            }

            // This is for getting the information of all properties
            $property = DB::table("properties")
            ->join("regions", "properties.region_id", "=", "regions.id")
            ->join("provinces", "properties.province_id", "=", "provinces.id")
            ->join("muncities", "properties.muncity_id", "=", "muncities.id")
            ->join("barangays", "properties.barangay_id", "=", "barangays.id")
            ->join("owners", "properties.owner_id", "=", "owners.id")
            ->join("users", "owners.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "properties.*",
                "users.*",
                "regions.regDesc",
                "provinces.provDesc",
                "barangays.brgyDesc",
                "muncities.muncityDesc",
                "property_types.type_name",
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as thumbnail_url")
            )
            ->where("properties.id", "=", $checkedId->id)
            ->first();

            // Get Property Images
            $propertyImages = DB::table("property_images")
            ->select(
                "property_images.*",
                DB::raw("CASE WHEN property_images.img_path IS NOT NULL THEN CONCAT('" . asset('storage') . "/', property_images.img_path) ELSE NULL END as images_url")
                )
            ->where("property_images.property_id", "=", $checkedId->id)
            ->get();

            // Get Property Facilities
            $propertyFacilities = DB::table("property_facilities")
            ->join("facilities", "property_facilities.facility_id", "=", "facilities.id")
            ->select(
                "property_facilities.property_id",
                "facilities.name",
            )
            ->where("property_facilities.property_id", "=", $checkedId->id)
            ->get();

            // Get Property Amenities
            $propertyAmenities = DB::table("property_amenities")
            ->join("amenities", "property_amenities.amenity_id", "=", "amenities.id")
            ->select("property_amenities.property_id", "amenities.name")
            ->where("property_amenities.property_id", "=", $checkedId->id)
            ->get();

            // Get Property Utilities
            $propertyUtilities = DB::table("property_utilities")
            ->join("utilities", "property_utilities.utility_id", "=", "utilities.id")
            ->select("property_utilities.property_id", "utilities.name")
            ->where("property_utilities.property_id", "=", $checkedId->id)
            ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'property' => $property,
                    'images' => $propertyImages,
                    'facilities' => $propertyFacilities,
                    'amenities' => $propertyAmenities,
                    'utilities' => $propertyUtilities,
                ]
            ], 200);

            


        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching property details.',
                'error' => $e->getMessage()
            ], 500);
        }
        
    }

    // Show Active properties
    public function getActiveProperties(){
        try {
            
            $properties = DB::table("properties")
            ->join("owners", "properties.owner_id", "=", "owners.id")
            ->join("users", "owners.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "properties.id as property_id",
                "properties.*",
                "users.*",
                "property_types.type_name",
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as thumbnail_url")
            )
            ->where("properties.status", "=", "active")
            ->paginate(10);

            if($properties->isEmpty()){
                return response()->json([
                    'status' => 'error',
                    'message' => 'No properties found.'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $properties
            ], 200);
            

        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "message" => $e->getMessage()
            ], 500);
        }
    }

    // Show Pending properties
    public function getPendingProperties(){
        try {
            
            $properties = DB::table("properties")
            ->join("owners", "properties.owner_id", "=", "owners.id")
            ->join("users", "owners.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "properties.*",
                "properties.id as property_id",
                "users.*",
                "property_types.type_name",
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as thumbnail_url")
            )
            ->where("properties.status", "=", "pending")
            ->paginate(10);

            if($properties->isEmpty()){
                return response()->json([
                    'status' => 'error',
                    'message' => 'No properties found.'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $properties
            ], 200);
            

        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "message" => $e->getMessage()
            ], 500);
        }
    }

    // Show Rejected properties
    public function getRejectedProperties(){
        try {
            
            $properties = DB::table("properties")
            ->join("owners", "properties.owner_id", "=", "owners.id")
            ->join("users", "owners.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "properties.*",
                "users.*",
                "property_types.type_name",
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as thumbnail_url")
            )
            ->where("properties.status", "=", "rejected")
            ->paginate(10);

            if($properties->isEmpty()){
                return response()->json([
                    'status' => 'error',
                    'message' => 'No properties found.'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $properties
            ], 200);
            

        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "message" => $e->getMessage()
            ], 500);
        }
    }


    // Actions for admins

    // Approve Property
    public function approveProperty($id){
        try {
            // Validate that the ID is a positive integer
            if (!is_numeric($id) || intval($id) <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid property ID provided.'
                ], 400);
            }
            $id = intval($id); // Ensure ID is an integer

            // check if property ID exists
            $checkedId = DB::table("properties")->where("id", "=", $id)->firstOrFail();
            if(!$checkedId){
                return response()->json([
                    'status' => 'error',
                    'message' => 'Property ID not found.'
                ], 404);
            }

            // Update property status to active
            DB::table("properties")
            ->where("id", "=", $checkedId->id)
            ->update([
                "status" => "active",
                "updated_at" => now()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Property approved successfully.'
            ], 200);
            
        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "message" => $e->getMessage()  
            ], 500);
        }
    }

}
