<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TenantsController extends Controller
{

    private function getOwner(){
        // * Gets the authenticated User
        $user = Auth::user();

        // * Check if the user is an owner
        $isOwner = DB::table("owners")
        ->where("user_id", $user->id)
        ->first();

        return $isOwner;
    }

    private function getPropertyTypeByPropertyId(int $propertyId){
        $property = DB::table("properties")
        ->where("id", $propertyId)
        ->first();

        if($property){
            return $property->property_type_id;
        }

        return null;
    }

    public function SelectTenantsByProperty(int $propertyId){
        try {

            $propertyType = $this->getPropertyTypeByPropertyId($propertyId);

            if(!$propertyType){
                return response()->json([
                    "error" => "Invalid property ID or property not found"
                ], 404);
            }

            // * Gets the authenticated User
            $isOwner = $this->getOwner();

            if(!$isOwner){
                return response()->json([
                    "error" => "Only owners can access tenants"
                ], 403);
            }

            //TODO: Select Tenants by the property type filter in the tenants table

            // Gets the tenants from property using the property id given
            $tenants = DB::table("tenants")
            ->join("properties", "tenants.property_id", "=", "properties.id")
            ->join("users", "tenants.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "tenants.*",
                "users.first_name",
                "users.last_name",
                "users.email as tenant_email",
                "properties.title as property_title",
                "property_types.type_name as property_type_name"
            )
            ->where("properties.id", "=", $propertyId)
            ->where("properties.owner_id", "=", $isOwner->id)
            ->where("properties.property_type_id", "=", $propertyType)
            ->get();
            

            if($tenants->isEmpty()){
                return response()->json([
                    "message" => "No tenants found for the specified property and type"
                ], 200);
            }

            return response()->json([
                "message" => "Tenants retrieved successfully",
                "data" => $tenants
            ], 200);


        } catch (\Exception $e) {
            //throw $th;
            return response()->json([
                "error" => "An error occurred while fetching tenants: " . $e->getMessage()
            ], 500);
        }
    }

    public function getAllTenants(){
        try {

            // * Gets the authenticated User
            $isOwner = $this->getOwner();

            if(!$isOwner){
                return response()->json([
                    "error" => "Only owners can access tenants"
                ], 403);
            }


            $tenants = DB::table("tenants")
            ->join("properties", "tenants.property_id", "=", "properties.id")
            ->join("users", "tenants.user_id", "=", "users.id")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select(
                "tenants.*",
                "users.first_name",
                "users.last_name",
                "users.email as tenant_email",
                "properties.title as property_title",
                "property_types.type_name as property_type_name"
            )
            ->where("properties.owner_id", "=", $isOwner->id)
            ->get();

            if($tenants->isEmpty()){
                return response()->json([
                    "message" => "No tenants found for your properties"
                ], 200);
            }

            return response()->json([
                "message" => "Tenants retrieved successfully",
                "data" => $tenants
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "error" => "An error occurred while fetching tenants: " . $e->getMessage()
            ], 500);
        }
    }
}
