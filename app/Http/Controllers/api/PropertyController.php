<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use PhpParser\Builder\Property;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PropertyRequest;
use App\Http\Requests\isSubscribingRequest;

class PropertyController extends Controller
{
    public function isSubscribing(isSubscribingRequest $request){
        logger($request->all());
        $validated = $request->validated();

        $user = DB::table('users')
        ->where('email', $validated['email'])
        ->where('first_name', $validated['first_name'])
        ->where('last_name', $validated['last_name'])
        ->first();

        if($user){
            DB::table('users')
            ->where('id', $user->id)
            ->update(['role' => 'owner']);
        } else {
            return response()->json([
                "message" => "User not found"
            ], 404);
        }

        $updatedUser = DB::table('users')->where('id', $user->id)->first();

        return response()->json([
            "message" => "Property owner subscription successful",
            "role" => $updatedUser->role
        ], 200);

    }

    public function getAmenities(){
        $amenities = DB::table("amenities")->get();
        return response()->json([
            "amenities" => $amenities
        ], 200);
    }

    public function getFacilities(){
        $facilities = DB::table("facilities")->get();
        return response()->json([
            "facilities" => $facilities
        ], 200);
    }

    public function getPropertyTypes(){
        $types = DB::table("property_types")->get();
        return response()->json([
            "types" => $types
        ],200);
    }

    // CRUD for Properties

    // Create Property
    // Create Property with Images
    // Create Property with Images
    public function createProperty(PropertyRequest $request)
    {
        // dd($request->all());
        $validated = $request->validated();

        DB::beginTransaction();

        try {
            // Handle thumbnail
            $thumbnailPath = null;
            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->store('property_thumbnails', 'public');
            }

            // Insert property
            $propertyId = DB::table('properties')->insertGetId([
                'owner_id' => Auth::id(),
                'title' => $validated['title'],
                'thumbnail' => $thumbnailPath, // only store relative path
                'description' => $validated['description'],
                'price' => $validated['price'],
                'utilities_included' => $validated['utilities_included'],
                'agreement_type' => $validated['agreement_type'],
                'advance_payment_months' => $validated['advance_payment'],
                'deposit_required' => $validated['deposit_required'],
                'payment_frequency' => $validated['payment_frequency'],
                'property_type_id' => $validated['property_type_id'],
                'furnishing' => $validated['furnishing'],
                'parking' => $validated['parking'],
                'is_available' => false,
                'bedrooms' => $validated['bedrooms'],
                'bathrooms' => $validated['bathrooms'],
                'bed_space' => $validated['bed_space'],
                'floor_area' => $validated['floor_area'],
                'lot_area' => $validated['lot_area'],
                'max_size' => $validated['max_size'],
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'region_id' => $validated['region_id'],
                'province_id' => $validated['province_id'],
                'muncity_id' => $validated['muncity_id'],
                'barangay_id' => $validated['barangay_id'],
                'rules' => $validated['rules'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Save property images
            $savedImages = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imagePath = $image->store('property_images', 'public');
                    DB::table('property_images')->insert([
                        'property_id' => $propertyId,
                        'img_path' => $imagePath,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $savedImages[] = asset('storage/' . $imagePath); // full URL
                }
            }

            // Save amenities
            if ($request->has('property_amenities')) {
                foreach ($validated['property_amenities'] as $amenityId) {
                    DB::table('property_amenities')->insert([
                        'property_id' => $propertyId,
                        'amenity_id' => $amenityId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Save facilities
            if ($request->has('property_facilities')) {
                foreach ($validated['property_facilities'] as $facilityId) {
                    DB::table('property_facilities')->insert([
                        'property_id' => $propertyId,
                        'facility_id' => $facilityId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Property created successfully!',
                'property_id' => $propertyId,
                'thumbnail_url' => $thumbnailPath ? asset('storage/' . $thumbnailPath) : null,
                'images' => $savedImages,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error creating property: ' . $e->getMessage(),
            ], 500);
        }
    }


    
    // Show a property
    public function showProperty($id)
    {
        // Fetch property, amenities, and facilities using DB queries
        $property = DB::table('properties')
            ->where('id', $id)
            ->first();

        $images = DB::table('property_images')
            ->where('property_id', $id)
            ->get();

        $amenities = DB::table('property_amenities')
            ->where('property_id', $id)
            ->join('amenities', 'property_amenities.amenity_id', '=', 'amenities.id')
            ->select('amenities.amenity_name')
            ->get();

        $facilities = DB::table('property_facilities')
            ->where('property_id', $id)
            ->join('facilities', 'property_facilities.facility_id', '=', 'facilities.id')
            ->select('facilities.facility_name')
            ->get();

        // Return the property details along with related data
        return response()->json([
            'property' => $property,
            'images' => $images,
            'amenities' => $amenities,
            'facilities' => $facilities,
        ]);
    }

    // Update Property
    public function updateProperty(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
            'utilities_included' => 'required|boolean',
            'agreement_type' => 'required|in:rental,lease',
            'property_type_id' => 'required|exists:property_types,id',
            'furnishing' => 'nullable|in:unfurnished,semi-furnished,fully-furnished',
            'parking' => 'required|boolean',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'muncity_id' => 'nullable|exists:muncities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
        ]);

        DB::table('properties')
            ->where('id', $id)
            ->update([
                'title' => $request->title,
                'description' => $request->description,
                'price' => $request->price,
                'utilities_included' => $request->utilities_included,
                'agreement_type' => $request->agreement_type,
                'advance_payment_months' => $request->advance_payment_months ?? 0,
                'deposit_required' => $request->deposit_required,
                'payment_frequency' => $request->payment_frequency ?? 'monthly',
                'property_type_id' => $request->property_type_id,
                'furnishing' => $request->furnishing,
                'parking' => $request->parking,
                'bedrooms' => $request->bedrooms,
                'bathrooms' => $request->bathrooms,
                'bed_space' => $request->bed_space,
                'floor_area' => $request->floor_area,
                'lot_area' => $request->lot_area,
                'max_size' => $request->max_size,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'region_id' => $request->region_id,
                'province_id' => $request->province_id,
                'muncity_id' => $request->muncity_id,
                'barangay_id' => $request->barangay_id,
                'rules' => $request->rules,
                'updated_at' => now(),
            ]);

        return response()->json(['id' => $id], 200);
    }

    // Delete Property
    public function deleteProperty($id)
    {
        DB::table('properties')->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    
}
