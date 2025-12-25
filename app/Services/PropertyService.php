<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PropertyService
{
    public function create(array $validated, $thumbnail = null, $images = [], $amenities = [], $facilities = [], $utilities = [])
    {
        DB::beginTransaction();

        try {

            $ownerid = DB::table("owners")->where("user_id", '=', Auth::id())->first();


            // Insert property
            $propertyId = DB::table('properties')->insertGetId([
                'owner_id' => $ownerid->id,
                'title' => $validated['title'],
                'thumbnail' => $thumbnail,
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'utilities_included' => $validated['utilities_included'] ?? false,
                'agreement_type' => $validated['agreement_type'],
                'advance_payment_months' => $validated['advance_payment_months'] ?? 0,
                'deposit_required' => $validated['deposit_required'] ?? null,
                'payment_frequency' => $validated['payment_frequency'] ?? 'monthly',
                'lease_term_months' => $validated['lease_term_months'] ?? null,
                'renewal_option' => $validated['renewal_option'] ?? null,
                'notice_period' => $validated['notice_period'] ?? null,
                'has_curfew' => $validated['has_curfew'] ?? false,
                'curfew_time' => $validated['curfew_time'] ?? null,
                'property_type_id' => $validated['property_type_id'],
                'furnishing' => $validated['furnishing'] ?? null,
                'parking' => $validated['parking'] ?? false,
                'is_available' => false,
                'bedrooms' => $validated['bedrooms'] ?? null,
                'bathrooms' => $validated['bathrooms'] ?? null,
                'bed_space' => $validated['bed_space'] ?? null,
                'floor_area' => $validated['floor_area'] ?? null,
                'lot_area' => $validated['lot_area'] ?? null,
                'max_size' => $validated['max_size'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'region_name' => $validated['region_name'] ?? null,
                'state_name' => $validated['state_name'] ?? null,
                'town_name' => $validated['town_name'] ?? null,
                'village_name' => $validated['village_name'] ?? null,
                'rules' => $validated['rules'] ?? null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Property images
            foreach ($images as $imagePath) {
                DB::table('property_images')->insert([
                    'property_id' => $propertyId,
                    'img_path' => $imagePath,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Amenities
            foreach ($amenities as $amenityId) {
                DB::table('property_amenities')->insert([
                    'property_id' => $propertyId,
                    'amenity_id' => $amenityId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Facilities
            foreach ($facilities as $facilityId) {
                DB::table('property_facilities')->insert([
                    'property_id' => $propertyId,
                    'facility_id' => $facilityId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Utilities
            foreach ($utilities as $utilityId) {
                DB::table('utilities')->insert([
                    'utility_name' => $utilityId,
                    'property_id' => $propertyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return $propertyId;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e; // let the controller handle errors
        }
    }
}
