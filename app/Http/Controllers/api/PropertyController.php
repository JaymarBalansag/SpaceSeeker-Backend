<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use App\Services\PropertyService;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PropertyRequest;
use PHPUnit\Framework\isEmpty;

use Illuminate\Support\Facades\Storage;
use App\Http\Requests\isSubscribingRequest;

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

            $utilities = $request->input('utilities', []);
            if(!empty($utilities)){
                $validated['utilities_included'] = true;
            } else {
                $validated['utilities_included'] = false;
            }

            // Call service
            $propertyId = $this->propertyService->create(
                $validated,
                $thumbnailPath,
                $imagePaths,
                $amenities,
                $facilities,
                $utilities
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
        try {
            $properties = DB::table("properties")
                ->select(
                    "properties.*",
                    DB::raw("
                        CASE 
                            WHEN properties.thumbnail IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) 
                            ELSE NULL 
                        END as image_url
                    ")
                )
                ->where("properties.status", "active")
                ->paginate(6); // ✅ pagination here

            // ❗ paginate() never returns empty collection directly
            if ($properties->total() === 0) {
                return response()->json([
                    "message" => "Please add a property",
                    "properties" => $properties
                ], 200);
            }

            return response()->json([
                "message" => "Property found",
                "properties" => $properties
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error getting property: " . $e->getMessage(),
            ], 500);
        }
    }


    // * READ PROPERTIES FOR Owner

    public function readOwnerProperties(){
        try{

            $ownerid = DB::table("owners")->where("user_id", "=", Auth::id())->first();

            $properties = DB::table("properties")
            ->select("properties.*",
            DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url"))
            ->where("owner_id", "=", $ownerid->id)
            ->get();

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

    public function showProperty($id)
    {
        try {
            // Fetch main property
            $property = DB::table('properties')
                ->join('property_types', 'properties.property_type_id', '=', 'property_types.id')
                ->join('owners', 'properties.owner_id', '=', 'owners.id')
                ->join('users', 'owners.user_id', '=', 'users.id')
                ->join("subscriptions", "owners.id", '=', "subscriptions.owner_id")
                ->select(
                    'properties.*',
                    'users.id as owner_id', 
                    'users.first_name as owner_first_name',
                    'users.last_name as owner_last_name',
                    'subscriptions.plan_name',
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img"),
                    'property_types.id as type_id',
                    'property_types.type_name',
                    DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
                ->where('properties.id', $id)
                ->first();

            if (!$property) {
                return response()->json([
                    'message' => 'Property not found'
                ], 404);
            }

            $images = DB::table('property_images')
            ->where('property_id', $id)
            ->pluck('img_path') // change column name if different
            ->map(function ($img) {
                return asset('storage/' . $img);
            }); 

            // Fetch related amenities
            $amenities = DB::table('property_amenities')
                ->join('amenities', 'property_amenities.amenity_id', '=', 'amenities.id')
                ->where('property_amenities.property_id', $id)
                ->pluck('amenities.amenity_name');

            // Fetch related facilities
            $facilities = DB::table('property_facilities')
                ->join('facilities', 'property_facilities.facility_id', '=', 'facilities.id')
                ->where('property_facilities.property_id', $id)
                ->pluck('facilities.facility_name');

            return response()->json([
                'property' => [
                    'id' => $property->id,
                    'owner_id' => $property->owner_id,
                    'owner_name' => $property->owner_first_name . ' ' . $property->owner_last_name,
                    'owner_profile_photo' => $property->user_img,
                    'title' => $property->title,
                    'description' => $property->description,
                    'price' => $property->price,
                    'payment_frequency' => $property->plan_name,
                    'agreement_type' => $property->agreement_type,
                    'type_id' => $property->type_id,
                    'type_name' => $property->type_name,
                    'image_url' => $property->thumbnail ? asset('storage/' . $property->thumbnail) : null,
                    'amenities' => $amenities,
                    'facilities' => $facilities,
                    'images' => $images,
                ],
                'message' => 'Property details fetched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching property details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPropertyByType($type_id, $property_id){
        try {
            
            $property = DB::table("properties")
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select("properties.*", "property_types.type_name",
            DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url"))
            ->where("properties.property_type_id", "=", $type_id,)
            ->where("properties.id", "!=", $property_id)
            ->where("properties.status", "=", "active")
            ->get();

            if (!$property) {
                return response()->json([
                    'message' => 'Property not found'
                ], 404);
            }

            return response()->json([
                "message" => "Property Found",
                "properties" => $property
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching property details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getFilteredProperty(Request $request)
    {
        // dd($request->all());
        try {
            $query = DB::table('properties')
                ->select('properties.*',
                    DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
                ->where("properties.status", "=", "active")
                ->distinct();

            // Filter by amenities
            if ($request->has('amenities') && !empty($request->amenities)) {
                $amenities = is_array($request->amenities) ? $request->amenities : explode(',', $request->amenities);

                $query->join('property_amenities', 'properties.id', '=', 'property_amenities.property_id')
                    ->whereIn('property_amenities.amenity_id', $amenities);
            }
            
            if ($request->has('facilities') && !empty($request->facilities)) {
                $facilities = is_array($request->facilities) ? $request->facilities : explode(',', $request->facilities);

                $query->join('property_facilities', 'properties.id', '=', 'property_facilities.property_id')
                    ->whereIn('property_facilities.facility_id', $facilities);
            }

            if ($request->has("selectedType") && !empty($request->selectedType)) {
                $types = (array) $request->selectedType;
                $query->join('property_types', 'properties.property_type_id', '=', 'property_types.id')
                    ->whereIn('property_types.id', $types); // ✅ remove "="
            }

            if ($request->has("selectedAgreement") && !empty($request->selectedAgreement)) {
                $agreements = (array) $request->selectedAgreement;
                $query->whereIn('properties.agreement_type', $agreements);
            }

            // Example: filter by price
            if ($request->has('min_price') && $request->has('max_price')) {
                $query->whereBetween('properties.price', [$request->min_price, $request->max_price]);
            }
            
            

            $properties = $query->paginate(6);

            return response()->json([
                'message' => 'Filtered properties fetched successfully',
                'properties' => $properties
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error fetching filtered properties: " . $e->getMessage()
            ], 500);
        }
    }

    public function getTypeFilter(Request $request) {
        try {
            $query = DB::table('properties')
                ->select(
                    'properties.*',
                    DB::raw("CASE WHEN properties.thumbnail IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) 
                            ELSE NULL END as image_url")
                )
                ->where("properties.status", "=", "active")
                ->distinct();

            if ($request->has("selectedType") && !empty($request->selectedType)) {
                $types = (array) $request->selectedType;
                $query->join('property_types', 'properties.property_type_id', '=', 'property_types.id')
                    ->whereIn('property_types.id', $types); // ✅ remove "="
            }

            if ($request->has("selectedAgreement") && !empty($request->selectedAgreement)) {
                $agreements = (array) $request->selectedAgreement;
                $query->whereIn('properties.agreement_type', $agreements);
            }

            $properties = $query->get();

            return response()->json([
                'message' => 'Filtered properties fetched successfully',
                'properties' => $properties
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error fetching filtered properties: " . $e->getMessage()
            ], 500);
        }
    }

    public function searchProperty($query, $page = 1){
        try {

            // return response()->json([
            //     'message' => 'Search query received',
            //     'search' => $query,
            //     'page' => $page,
            // ]);

            $page = $page ?? 1;
            $search = trim($query);

            

            $query = DB::table('properties')
                ->select(
                    'properties.*',
                    DB::raw("CASE WHEN properties.thumbnail IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) 
                            ELSE NULL END as image_url")
                )
                ->where('properties.status', 'active');

            $query->where(function ($q) use ($search) {
                $q->where('properties.title', 'LIKE', "%{$search}%")
                ->orWhere('properties.region_name', 'LIKE', "%{$search}%")
                ->orWhere('properties.state_name', 'LIKE', "%{$search}%")
                ->orWhere('properties.town_name', 'LIKE', "%{$search}%")
                ->orWhere('properties.village_name', 'LIKE', "%{$search}%");
            });

            // return response()->json([ 'sql' => $query->toSql(), 'bindings' => $query->getBindings(), ]);

            $properties = $query->paginate(6, ['*'], 'page', $page);

            return response()->json([
                'message' => 'Properties fetched',
                'properties' => $properties
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error searching properties: ' . $e->getMessage()
            ], 500);
        }
    }



}
