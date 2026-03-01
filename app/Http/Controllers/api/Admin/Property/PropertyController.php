<?php

namespace App\Http\Controllers\Api\Admin\Property;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;

class PropertyController extends Controller
{
    public function showPropertyDetails($id)
    {
        return $this->ShowProperties($id);
    }

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
            $checkedId = DB::table("properties")->where("id", "=", $id)->first();
            if(!$checkedId){
                return response()->json([
                    'status' => 'error',
                    'message' => 'Property ID not found.'
                ], 404);
            }

            // Build a resilient details query. Local DB may not have all lookup tables.
            $hasRegions = Schema::hasTable('regions');
            $hasProvinces = Schema::hasTable('provinces');
            $hasMunCities = Schema::hasTable('muncities');
            $hasBarangays = Schema::hasTable('barangays');
            $hasOwners = Schema::hasTable('owners');
            $hasUsers = Schema::hasTable('users');
            $hasPropertyTypes = Schema::hasTable('property_types');

            $propertyQuery = DB::table("properties")->select("properties.*");

            if ($hasRegions) {
                $propertyQuery->leftJoin("regions", "properties.region_id", "=", "regions.id")
                    ->addSelect("regions.regDesc");
            } else {
                $propertyQuery->addSelect(DB::raw("properties.region_name as regDesc"));
            }

            if ($hasProvinces) {
                $propertyQuery->leftJoin("provinces", "properties.province_id", "=", "provinces.id")
                    ->addSelect("provinces.provDesc");
            } else {
                $propertyQuery->addSelect(DB::raw("properties.state_name as provDesc"));
            }

            if ($hasMunCities) {
                $propertyQuery->leftJoin("muncities", "properties.muncity_id", "=", "muncities.id")
                    ->addSelect("muncities.muncityDesc");
            } else {
                $propertyQuery->addSelect(DB::raw("properties.town_name as muncityDesc"));
            }

            if ($hasBarangays) {
                $propertyQuery->leftJoin("barangays", "properties.barangay_id", "=", "barangays.id")
                    ->addSelect("barangays.brgyDesc");
            } else {
                $propertyQuery->addSelect(DB::raw("properties.village_name as brgyDesc"));
            }

            if ($hasOwners) {
                $propertyQuery->leftJoin("owners", "properties.owner_id", "=", "owners.id");

                if ($hasUsers) {
                    $propertyQuery->leftJoin("users", "owners.user_id", "=", "users.id")
                        ->addSelect("users.*");
                }
            }

            if ($hasPropertyTypes) {
                $propertyQuery->leftJoin("property_types", "properties.property_type_id", "=", "property_types.id")
                    ->addSelect("property_types.type_name");
            } else {
                $propertyQuery->addSelect(DB::raw("NULL as type_name"));
            }

            $property = $propertyQuery
                ->addSelect(DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as thumbnail_url"))
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
                "facilities.facility_name as name",
            )
            ->where("property_facilities.property_id", "=", $checkedId->id)
            ->get();

            // Get Property Amenities
            $propertyAmenities = DB::table("property_amenities")
            ->join("amenities", "property_amenities.amenity_id", "=", "amenities.id")
            ->select("property_amenities.property_id", "amenities.amenity_name as name")
            ->where("property_amenities.property_id", "=", $checkedId->id)
            ->get();

            // Get Property Utilities
            $propertyUtilities = DB::table("utilities")
            ->select(
                "utilities.property_id",
                "utilities.utility_name as name"
            )
            ->where("utilities.property_id", "=", $checkedId->id)
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
            Log::error('Failed to fetch admin property details', [
                'property_id' => $id ?? null,
                'error' => $e->getMessage(),
            ]);

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

    public function rejectProperty(Request $request, $id)
    {
        try {
            if (!is_numeric($id) || intval($id) <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid property ID provided.'
                ], 400);
            }
            $id = intval($id);

            $checkedId = DB::table("properties")->where("id", "=", $id)->firstOrFail();
            if (!$checkedId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Property ID not found.'
                ], 404);
            }

            DB::table("properties")
                ->where("id", "=", $checkedId->id)
                ->update([
                    "status" => "rejected",
                    "updated_at" => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Property rejected successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "message" => $e->getMessage()
            ], 500);
        }
    }

}
