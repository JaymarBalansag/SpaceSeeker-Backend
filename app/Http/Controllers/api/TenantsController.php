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

    public function moveInTenant(Request $request, $id)
    {
        // Start the transaction
        DB::beginTransaction();

        try {
            // 1. Update the Tenant Status
            $tenantUpdated = DB::table('tenants')
                ->where('id', $id)
                ->update([
                    'status' => 'active',
                    'updated_at' => now(),
                    // 'move_in_date' => now(), 
                ]);

            if (!$tenantUpdated) {
                throw new \Exception("Tenant not found or already active.");
            }

            // 2. Update the related Billing from 'pending' to 'unpaid'
            // We only update the most recent pending bill for this tenant
            DB::table('billings')
                ->where('tenant_id', $id)
                ->where('rent_status', 'pending')
                ->update([
                    'rent_status' => 'unpaid',
                    'updated_at' => now()
                ]);

            // If everything is successful, save changes to database
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Tenant is now Active and billing has been issued.'
            ], 200);

        } catch (\Exception $e) {
            // If anything goes wrong, undo ALL changes
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Move-in failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getMyBillings(Request $request) {
        $userId = Auth::id(); 

        // 1. Get just the 'id' value. Using value('id') returns the string/int directly or null.
        $tenantId = DB::table("tenants")->where("user_id", $userId)->value("id");

        // 2. Security Check: If they aren't a tenant, don't even run the billing query
        if (!$tenantId) {
            return response()->json([
                'data' => [],
                'message' => 'No tenant profile found for this user.'
            ], 404);
        }

        // 3. Get the billings
        $billings = DB::table('billings')
            ->join('properties', 'billings.property_id', '=', 'properties.id')
            ->where('billings.tenant_id', $tenantId) // $tenantId is now the raw ID
            ->select(
                'billings.id',
                'billings.rent_amount',
                'billings.rent_due',
                'billings.rent_status',
                'billings.rent_cycle',
                'properties.title as property_title'
            )
            ->orderBy('billings.rent_due', 'desc')
            ->get();

        return response()->json(['data' => $billings]);
    }

    public function submitPayment(Request $request) {
        // 1. Get Tenant ID from Auth
        $tenantId = DB::table('tenants')->where('user_id', Auth::id())->value('id');

        // 2. Handle File Upload
        $proofPath = null;
        if ($request->hasFile('proof')) {
            $proofPath = $request->file('proof')->store('proofs', 'public');
        }

        // 3. Insert Payment Record
        DB::table('payments')->insert([
            'billing_id'        => $request->billing_id,
            'amount_paid'       => $request->amount_paid,
            'payment_method'    => $request->payment_method,
            'payment_reference' => $request->payment_reference, // Nullable
            'proof'             => $proofPath,
            'remarks'           => $request->remarks,
            'status'            => 'pending', // Waiting for owner review
            'date_paid'         => now(),
            'created_at'        => now()
        ]);

        
        return response()->json(['message' => 'Payment submitted successfully']);
    }
}
