<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Services\PropertyService;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PropertyRequest;
use App\Http\Requests\isSubscribingRequest;
use Exception;

use function PHPUnit\Framework\isEmpty;

class PropertyController extends Controller
{

    public function __construct(PropertyService $propertyService)
    {
        $this->propertyService = $propertyService;
    }

    public function isSubscribing(isSubscribingRequest $request){
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

    protected $propertyService;

    

    // Create Property
    public function createProperty(PropertyRequest $request)
    {
        try {
            $validated = $request->validated();

            // Extract thumbnail path
            $thumbnailPath = null;
            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'public');
            }

            // Extract images
            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imagePaths[] = $image->store('property_images', 'public');
                }
            }

            // Amenities & facilities (arrays from formData)
            $amenities = $request->input('property_amenities', []);
            $facilities = $request->input('property_facilities', []);

            // Call service
            $propertyId = $this->propertyService->create(
                $validated,
                $thumbnailPath,
                $imagePaths,
                $amenities,
                $facilities
            );

            return response()->json([
                'message' => 'Property created successfully!',
                'property_id' => $propertyId,
                'thumbnail_url' => $thumbnailPath,
                'images' => $imagePaths,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating property: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function readProperties(){
        try{
            $properties = DB::table("properties")
            ->join("regions", "properties.region_id", "=", "regions.id")
            ->join("provinces", "properties.province_id", "=", "provinces.id")
            ->select(
                "properties.*", 
            "regions.regDesc", 
            "provinces.provDesc",
            DB::raw("CONCAT('" . asset('storage/properties') . "/', properties.thumbnail) as image_url")
            )->get();

            if($properties->isEmpty()){
                return response()->json([
                    "message" => "Please add a property",
                ], 404);
            }

            return response()->json([
                "message" => "Property found",
                "properties" => $properties
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => "Error getting property: " . $e->getMessage(),
            ], 500);
        }
    }

    // * READ PROPERTIES FOR HOMES




    
}
