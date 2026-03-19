<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use App\Services\PropertyService;
use App\Services\NotificationService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Observers\Notifications\Logic\NotificationLogicObserver;
use App\Http\Requests\PropertyRequest;
use App\Http\Requests\PropertyReviewRequest;

use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use App\Http\Requests\isSubscribingRequest;

class PropertyController extends Controller
{
    protected PropertyService $propertyService;
    protected SubscriptionLifecycleService $subscriptionLifecycleService;
    protected NotificationService $notificationService;
    protected NotificationLogicObserver $notificationLogicObserver;

    public function __construct(
        PropertyService $propertyService,
        SubscriptionLifecycleService $subscriptionLifecycleService,
        NotificationService $notificationService,
        NotificationLogicObserver $notificationLogicObserver
    )
    {
        $this->propertyService = $propertyService;
        $this->subscriptionLifecycleService = $subscriptionLifecycleService;
        $this->notificationService = $notificationService;
        $this->notificationLogicObserver = $notificationLogicObserver;
    }

    private function cleanFeatureValue($value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function uniqueFeatures(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $clean = $this->cleanFeatureValue($item);
            if ($clean === null) {
                continue;
            }

            $key = strtolower($clean);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $clean;
            }
        }

        return $result;
    }

    private function parseIdList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value), fn ($v) => $v > 0));
        }

        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $value)), fn ($v) => $v > 0));
    }

    private function attachPropertyFeatureChips($properties, array $selectedAmenityIds = [], array $selectedFacilityIds = []): void
    {
        $propertyIds = collect($properties)->pluck('id')->filter()->values()->all();
        if (empty($propertyIds)) {
            return;
        }

        $systemAmenities = DB::table('property_amenities')
            ->join('amenities', 'amenities.id', '=', 'property_amenities.amenity_id')
            ->whereIn('property_amenities.property_id', $propertyIds)
            ->select('property_amenities.property_id', 'amenities.amenity_name as feature_name')
            ->get()
            ->groupBy('property_id');

        $customAmenities = DB::table('custom_amenities')
            ->whereIn('property_id', $propertyIds)
            ->select('property_id', 'custom_amenity as feature_name')
            ->get()
            ->groupBy('property_id');

        $systemFacilities = DB::table('property_facilities')
            ->join('facilities', 'facilities.id', '=', 'property_facilities.facility_id')
            ->whereIn('property_facilities.property_id', $propertyIds)
            ->select('property_facilities.property_id', 'facilities.facility_name as feature_name')
            ->get()
            ->groupBy('property_id');

        $customFacilities = DB::table('custom_facilities')
            ->whereIn('property_id', $propertyIds)
            ->select('property_id', 'custom_facility as feature_name')
            ->get()
            ->groupBy('property_id');

        $selectedAmenityNames = empty($selectedAmenityIds)
            ? []
            : DB::table('amenities')
                ->whereIn('id', $selectedAmenityIds)
                ->pluck('amenity_name')
                ->map(fn ($name) => $this->cleanFeatureValue($name))
                ->filter()
                ->values()
                ->all();

        $selectedFacilityNames = empty($selectedFacilityIds)
            ? []
            : DB::table('facilities')
                ->whereIn('id', $selectedFacilityIds)
                ->pluck('facility_name')
                ->map(fn ($name) => $this->cleanFeatureValue($name))
                ->filter()
                ->values()
                ->all();

        $hasFilterContext = !empty($selectedAmenityNames) || !empty($selectedFacilityNames);

        foreach ($properties as $property) {
            $propertyId = $property->id;

            $amenityValues = $this->uniqueFeatures(array_merge(
                collect($systemAmenities->get($propertyId, []))->pluck('feature_name')->all(),
                collect($customAmenities->get($propertyId, []))->pluck('feature_name')->all()
            ));

            $facilityValues = $this->uniqueFeatures(array_merge(
                collect($systemFacilities->get($propertyId, []))->pluck('feature_name')->all(),
                collect($customFacilities->get($propertyId, []))->pluck('feature_name')->all()
            ));

            $defaultChips = array_slice($amenityValues, 0, 3);
            $defaultCount = count($amenityValues);

            if (!$hasFilterContext) {
                $property->display_chips = $defaultChips;
                $property->display_chips_more_count = max($defaultCount - count($defaultChips), 0);
                continue;
            }

            $amenityLookup = [];
            foreach ($amenityValues as $name) {
                $amenityLookup[strtolower($name)] = $name;
            }

            $facilityLookup = [];
            foreach ($facilityValues as $name) {
                $facilityLookup[strtolower($name)] = $name;
            }

            $matched = [];
            foreach ($selectedAmenityNames as $name) {
                $key = strtolower($name);
                if (isset($amenityLookup[$key])) {
                    $matched[] = $amenityLookup[$key];
                }
            }

            foreach ($selectedFacilityNames as $name) {
                $key = strtolower($name);
                if (isset($facilityLookup[$key])) {
                    $matched[] = $facilityLookup[$key];
                }
            }

            $matched = $this->uniqueFeatures($matched);

            if (!empty($matched)) {
                $chips = array_slice($matched, 0, 3);
                $property->display_chips = $chips;
                $property->display_chips_more_count = max(count($matched) - count($chips), 0);
            } else {
                // Fallback to default amenities when none of the selected filters match this card.
                $property->display_chips = $defaultChips;
                $property->display_chips_more_count = max($defaultCount - count($defaultChips), 0);
            }

        }
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

    public function getCategoryCounts(){
        try {
            $count = DB::table('property_types')
                ->leftJoin('properties', 'properties.property_type_id', '=', 'property_types.id')
                ->select(
                    'property_types.id',
                    'property_types.type_name',
                    DB::raw('COUNT(properties.id) as total')
                )
                ->groupBy('property_types.id', 'property_types.type_name')
                ->get();

            $propertyCount = DB::table('properties')->count();
            
            $tenantCount = DB::table('tenants')->count();

            if($count->isEmpty()){
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No property found.'
                ]);
            }

            return response()->json([
                'status' => 'success',
                'data' => $count,
                'propertyCount' => $propertyCount,
                'tenantCount' => $tenantCount,
                'message' => 'Property Count retrieved successfully.'
            ]);

        } catch (\Throwable $th) {

            return response()->json([
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ], 500);
        }
    }

    // Create Property
    public function createProperty(PropertyRequest $request)
    {
        try {
            $subscriptionGuard = $this->ensureOwnerCanManageProperties();
            if ($subscriptionGuard) {
                return $subscriptionGuard;
            }

            $listingGuard = $this->ensureOwnerHasListingSlot();
            if ($listingGuard) {
                return $listingGuard;
            }

            $validated = $request->validated();

            // Extract thumbnail path
            $thumbnailPath = null;
            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'public');
            }

            $businessPermitPath = null;
            if ($request->hasFile('business_permit')) {
                $businessPermitPath = $request->file('business_permit')->store('property_permits', 'public');
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

            $customAmenities = $request->input('custom_amenities', []);
            $customFacilities = $request->input('custom_facilities', []);

            $utilities = $request->input('utilities', []);
            $customUtilities = $request->input('custom_utilities', []);
            if(!empty($utilities)){
                $validated['utilities_included'] = true;
            } else {
                $validated['utilities_included'] = false;
            }

            // Call service
            $propertyId = $this->propertyService->create(
                $validated,
                $thumbnailPath,
                $businessPermitPath,
                $imagePaths,
                $amenities,
                $facilities,
                $utilities,
                $customUtilities,
                $customAmenities,
                $customFacilities,
                
            );

            return response()->json([
                'message' => 'Property created successfully!',
                'property_id' => $propertyId,
                'thumbnail_url' => $thumbnailPath,
                'business_permit_url' => $businessPermitPath ? asset('storage/' . $businessPermitPath) : null,
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
            $reviewSummary = DB::table('property_reviews')
                ->select(
                    'property_id',
                    DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                    DB::raw('COUNT(*) as total_reviews')
                )
                ->groupBy('property_id');

            $properties = DB::table("properties")
                ->leftJoin('owners', 'owners.id', '=', 'properties.owner_id')
                ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                    $join->on('review_summary.property_id', '=', 'properties.id');
                })
                ->select(
                    "properties.*",
                    "owners.owner_verification_status",
                    DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
                    DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews'),
                    DB::raw("
                        CASE 
                            WHEN properties.thumbnail IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) 
                            ELSE NULL 
                        END as image_url
                    ")
                )
                ->where("properties.status", "active")
                ->paginate(4); // ✅ pagination here

            $this->attachPropertyFeatureChips($properties->getCollection());


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

    public function recordView(Request $request, $propertyId){
        // Get the property + owner
        $property = DB::table('properties')
            ->select('id', 'owner_id')
            ->where('id', $propertyId)
            ->first();

        if (!$property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        $userId = null;
        $bearer = $request->bearerToken(); // Authorization: Bearer TOKEN
        if ($bearer) {
            $token = PersonalAccessToken::findToken($bearer);
            if ($token) {
                $userId = $token->tokenable_id; // logged-in user's ID
            }
        }

        if ($userId) {
            // Get the owner's user_id of this property
            $ownerUserId = DB::table('owners')
                ->where('id', $property->owner_id)
                ->value('user_id'); // just get the user_id

            // If current user is the owner → ignore
            if ($ownerUserId == $userId) {
                return response()->json(['ignored' => true]);
            }
        }

        // Guest or non-owner → increment views
        DB::table('properties')
            ->where('id', $propertyId)
            ->increment('views');

        return response()->json(['success' => true]);
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

    public function updateAvailability(Request $request, int $id)
    {
        try {
            $request->validate([
                'is_available' => 'required|boolean',
                'status' => 'nullable|in:active,pending,inactive',
            ]);

            $ownerId = DB::table("owners")
                ->where("user_id", Auth::id())
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    'message' => 'Owner profile not found.'
                ], 404);
            }

            $property = DB::table("properties")
                ->where("id", $id)
                ->where("owner_id", $ownerId)
                ->first();

            if (!$property) {
                return response()->json([
                    'message' => 'Property not found or unauthorized.'
                ], 404);
            }

            $updatePayload = [
                'is_available' => (bool) $request->boolean('is_available'),
                'updated_at' => now(),
            ];
            if ($request->filled('status')) {
                $updatePayload['status'] = $request->input('status');
            }

            DB::table("properties")
                ->where("id", $id)
                ->update($updatePayload);

            return response()->json([
                'message' => $request->boolean('is_available')
                    ? 'Property marked as available.'
                    : 'Property marked as fully booked.',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Unable to update property availability.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function deleteOwnerProperty(int $id)
    {
        try {
            $ownerId = DB::table("owners")
                ->where("user_id", Auth::id())
                ->value("id");

            if (!$ownerId) {
                return response()->json([
                    'message' => 'Owner profile not found.'
                ], 404);
            }

            $property = DB::table("properties")
                ->where("id", $id)
                ->where("owner_id", $ownerId)
                ->first();

            if (!$property) {
                return response()->json([
                    'message' => 'Property not found or unauthorized.'
                ], 404);
            }

            return DB::transaction(function () use ($property, $id) {
                $billingIds = DB::table('billings')
                    ->where('property_id', $id)
                    ->pluck('id');

                if ($billingIds->isNotEmpty()) {
                    DB::table('payments')
                        ->whereIn('billing_id', $billingIds->all())
                        ->delete();
                }

                DB::table('billings')->where('property_id', $id)->delete();

                $imagePaths = DB::table('property_images')
                    ->where('property_id', $id)
                    ->pluck('img_path')
                    ->filter()
                    ->values()
                    ->all();

                DB::table('property_images')->where('property_id', $id)->delete();

                DB::table('property_amenities')->where('property_id', $id)->delete();
                DB::table('property_facilities')->where('property_id', $id)->delete();
                DB::table('utilities')->where('property_id', $id)->delete();
                DB::table('custom_utilities')->where('property_id', $id)->delete();
                DB::table('custom_amenities')->where('property_id', $id)->delete();
                DB::table('custom_facilities')->where('property_id', $id)->delete();
                DB::table('property_reviews')->where('property_id', $id)->delete();
                DB::table('bookings')->where('property_id', $id)->delete();
                DB::table('tenants')->where('property_id', $id)->delete();

                DB::table('properties')->where('id', $id)->delete();

                if (!empty($property->thumbnail)) {
                    Storage::disk('public')->delete($property->thumbnail);
                }
                if (!empty($imagePaths)) {
                    Storage::disk('public')->delete($imagePaths);
                }

                return response()->json([
                    'message' => 'Property deleted successfully.'
                ], 200);
            });
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Unable to delete property.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function showProperty($id)
    {
        try {
            $reviewSummary = DB::table('property_reviews')
                ->select(
                    'property_id',
                    DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                    DB::raw('COUNT(*) as total_reviews')
                )
                ->groupBy('property_id');

            // Fetch main property
            $property = DB::table('properties')
                ->join('property_types', 'properties.property_type_id', '=', 'property_types.id')
                ->join('owners', 'properties.owner_id', '=', 'owners.id')
                ->join('users', 'owners.user_id', '=', 'users.id')
                ->join("subscriptions", "owners.id", '=', "subscriptions.owner_id")
                ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                    $join->on('review_summary.property_id', '=', 'properties.id');
                })
                ->select(
                    'properties.*',
                    'users.id as owner_id', 
                    'users.first_name as owner_first_name',
                    'users.last_name as owner_last_name',
                    'owners.owner_verification_status',
                    DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
                    DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews'),
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

            $customAmenities = DB::table('custom_amenities')
                ->where('property_id', $id)
                ->pluck('custom_amenity');

            $customFacilities = DB::table('custom_facilities')
                ->where('property_id', $id)
                ->pluck('custom_facility');

            $utilities = DB::table('utilities')
                ->where('property_id', $id)
                ->pluck('utility_name');

            $customUtilities = DB::table('custom_utilities')
                ->where('property_id', $id)
                ->pluck('custom_utility');

            return response()->json([
                'property' => [
                    'id' => $property->id,
                    'owner_id' => $property->owner_id,
                    'owner_name' => $property->owner_first_name . ' ' . $property->owner_last_name,
                    'owner_profile_photo' => $property->user_img,
                    'owner_verification_status' => $property->owner_verification_status ?? 'unverified',
                    'title' => $property->title,
                    'description' => $property->description,
                    'price' => $property->price,
                    'payment_frequency' => $property->payment_frequency,
                    'agreement_type' => $property->agreement_type,
                    'type_id' => $property->type_id,
                    'type_name' => $property->type_name,
                    'image_url' => $property->thumbnail ? asset('storage/' . $property->thumbnail) : null,
                    'amenities' => $amenities,
                    'custom_amenities' => $customAmenities,
                    'facilities' => $facilities,
                    'custom_facilities' => $customFacilities,
                    'utilities' => $utilities,
                    'custom_utilities' => $customUtilities,
                    'utilities_included' => (bool) $property->utilities_included,
                    'furnishing' => $property->furnishing,
                    'bedrooms' => $property->bedrooms,
                    'single_bed' => $property->single_bed,
                    'double_bed' => $property->double_bed,
                    'public_bath' => $property->public_bath,
                    'private_bath' => $property->private_bath,
                    'bed_space' => $property->bed_space,
                    'floor_area' => $property->floor_area,
                    'lot_area' => $property->lot_area,
                    'max_size' => $property->max_size,
                    'advance_payment_months' => $property->advance_payment_months,
                    'deposit_required' => $property->deposit_required,
                    'lease_term_months' => $property->lease_term_months,
                    'renewal_option' => $property->renewal_option,
                    'notice_period' => $property->notice_period,
                    'rules' => $property->rules,
                    'has_curfew' => (bool) $property->has_curfew,
                    'curfew_from' => $property->curfew_from,
                    'curfew_to' => $property->curfew_to,
                    'status' => $property->status,
                    'is_available' => (bool) $property->is_available,
                    'region_name' => $property->region_name,
                    'state_name' => $property->state_name,
                    'town_name' => $property->town_name,
                    'village_name' => $property->village_name,
                    'images' => $images,
                    'latitude' => $property->latitude,
                    'longitude' => $property->longitude,
                    'average_rating' => (float) $property->average_rating,
                    'total_reviews' => (int) $property->total_reviews,
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
            $reviewSummary = DB::table('property_reviews')
                ->select(
                    'property_id',
                    DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                    DB::raw('COUNT(*) as total_reviews')
                )
                ->groupBy('property_id');
            
            $property = DB::table("properties")
            ->leftJoin("owners", "owners.id", "=", "properties.owner_id")
            ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                $join->on('review_summary.property_id', '=', 'properties.id');
            })
            ->join("property_types", "properties.property_type_id", "=", "property_types.id")
            ->select("properties.*", "property_types.type_name", "owners.owner_verification_status",
            DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
            DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews'),
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

    public function getPropertyReviews($id)
    {
        try {
            $propertyExists = DB::table('properties')->where('id', $id)->exists();
            if (!$propertyExists) {
                return response()->json([
                    'message' => 'Property not found'
                ], 404);
            }

            $reviews = DB::table('property_reviews')
                ->join('users', 'property_reviews.user_id', '=', 'users.id')
                ->select(
                    'property_reviews.id',
                    'property_reviews.rating',
                    'property_reviews.comment',
                    'property_reviews.created_at',
                    DB::raw("CONCAT(users.first_name, ' ', users.last_name) as user_name"),
                    DB::raw("CASE WHEN users.user_img IS NOT NULL THEN CONCAT('" . asset('storage') . "/', users.user_img) ELSE NULL END as user_img")
                )
                ->where('property_reviews.property_id', $id)
                ->orderByDesc('property_reviews.created_at')
                ->get();

            $totalReviews = $reviews->count();
            $averageRating = $totalReviews > 0 ? round((float) $reviews->avg('rating'), 1) : 0;

            return response()->json([
                'reviews' => $reviews,
                'average_rating' => $averageRating,
                'total_reviews' => $totalReviews,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching property reviews',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitPropertyReview(PropertyReviewRequest $request, $id)
    {
        try {
            $userId = Auth::id();
            $validated = $request->validated();

            $property = DB::table('properties')
                ->select('id', 'owner_id')
                ->where('id', $id)
                ->first();

            if (!$property) {
                return response()->json([
                    'message' => 'Property not found'
                ], 404);
            }

            $ownerUserId = DB::table('owners')
                ->where('id', $property->owner_id)
                ->value('user_id');

            if ((int) $ownerUserId === (int) $userId) {
                return response()->json([
                    'message' => 'Owners cannot review their own property.'
                ], 403);
            }

            $isEligibleTenant = DB::table('tenants')
                ->where('user_id', $userId)
                ->where('property_id', $id)
                ->where(function ($query) {
                    $query->where('status', 'active')
                        ->orWhereDate('move_in_date', '<=', now()->toDateString());
                })
                ->exists();

            if (!$isEligibleTenant) {
                return response()->json([
                    'message' => 'Only tenants who have moved in can review this property.'
                ], 403);
            }

            $existingReview = DB::table('property_reviews')
                ->where('property_id', $id)
                ->where('user_id', $userId)
                ->first();

            if ($existingReview) {
                DB::table('property_reviews')
                    ->where('id', $existingReview->id)
                    ->update([
                        'rating' => $validated['rating'],
                        'comment' => $validated['comment'],
                        'updated_at' => now(),
                    ]);

                $tenantName = trim((string) DB::table('users')
                    ->where('id', $userId)
                    ->selectRaw("CONCAT(first_name, ' ', last_name) as full_name")
                    ->value('full_name'));
                $propertyName = (string) DB::table('properties')->where('id', $id)->value('title');

                if ($ownerUserId) {
                    $payload = $this->notificationLogicObserver->buildTenantReviewPayload(
                        $tenantName !== '' ? $tenantName : 'A tenant',
                        $propertyName !== '' ? $propertyName : 'your property',
                        (int) $validated['rating']
                    );
                    $this->notificationService->createForUser((int) $ownerUserId, $payload);
                }

                return response()->json([
                    'message' => 'Review updated successfully.',
                ], 200);
            }

            DB::table('property_reviews')->insert([
                'property_id' => $id,
                'user_id' => $userId,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tenantName = trim((string) DB::table('users')
                ->where('id', $userId)
                ->selectRaw("CONCAT(first_name, ' ', last_name) as full_name")
                ->value('full_name'));
            $propertyName = (string) DB::table('properties')->where('id', $id)->value('title');

            if ($ownerUserId) {
                $payload = $this->notificationLogicObserver->buildTenantReviewPayload(
                    $tenantName !== '' ? $tenantName : 'A tenant',
                    $propertyName !== '' ? $propertyName : 'your property',
                    (int) $validated['rating']
                );
                $this->notificationService->createForUser((int) $ownerUserId, $payload);
            }

            return response()->json([
                'message' => 'Review submitted successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error submitting property review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getFilteredProperty(Request $request){
        try {
            $selectedAmenityIds = $this->parseIdList($request->input('amenities'));
            $selectedFacilityIds = $this->parseIdList($request->input('facilities'));

            $reviewSummary = DB::table('property_reviews')
                ->select(
                    'property_id',
                    DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                    DB::raw('COUNT(*) as total_reviews')
                )
                ->groupBy('property_id');

            $query = DB::table('properties')
                ->leftJoin('owners', 'owners.id', '=', 'properties.owner_id')
                ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                    $join->on('review_summary.property_id', '=', 'properties.id');
                })
                ->select('properties.*',
                    'owners.owner_verification_status',
                    DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
                    DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews'),
                    DB::raw("CASE WHEN properties.thumbnail IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) 
                            ELSE NULL END as image_url")
                )
                ->where("properties.status", "=", "active");

            // 1. Filter by Amenities (using subquery to avoid row duplication)
            if (!empty($selectedAmenityIds)) {
                $query->whereExists(function ($q) use ($selectedAmenityIds) {
                    $q->select(DB::raw(1))
                    ->from('property_amenities')
                    ->whereRaw('property_amenities.property_id = properties.id')
                    ->whereIn('property_amenities.amenity_id', $selectedAmenityIds);
                });
            }

            // 2. Filter by Facilities
            if (!empty($selectedFacilityIds)) {
                $query->whereExists(function ($q) use ($selectedFacilityIds) {
                    $q->select(DB::raw(1))
                    ->from('property_facilities')
                    ->whereRaw('property_facilities.property_id = properties.id')
                    ->whereIn('property_facilities.facility_id', $selectedFacilityIds);
                });
            }

            // 3. Property Type
            if ($request->filled('selectedType')) {
                $types = (array) $request->selectedType;
                $query->whereIn('properties.property_type_id', $types);
            }

            // 4. Agreement Type
            if ($request->filled('selectedAgreement')) {
                $agreements = (array) $request->selectedAgreement;
                $query->whereIn('properties.agreement_type', $agreements);
            }

            // 5. Independent Price Filters (One or both can be present)
            if ($request->filled('min_price')) {
                $query->where('properties.price', '>=', $request->min_price);
            }
            if ($request->filled('max_price')) {
                $query->where('properties.price', '<=', $request->max_price);
            }

            // Final Paginate
            $properties = $query->paginate(4);
            $this->attachPropertyFeatureChips(
                $properties->getCollection(),
                $selectedAmenityIds,
                $selectedFacilityIds
            );

            return response()->json([
                'message' => 'Filtered properties fetched successfully',
                'properties' => $properties
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => "Error fetching properties: " . $e->getMessage()
            ], 500);
        }
    }

    public function getTypeFilter(Request $request) {
        try {
            $reviewSummary = DB::table('property_reviews')
                ->select(
                    'property_id',
                    DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                    DB::raw('COUNT(*) as total_reviews')
                )
                ->groupBy('property_id');

            $query = DB::table('properties')
                ->leftJoin('owners', 'owners.id', '=', 'properties.owner_id')
                ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                    $join->on('review_summary.property_id', '=', 'properties.id');
                })
                ->select(
                    'properties.*',
                    'owners.owner_verification_status',
                    DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
                    DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews'),
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

            

            $reviewSummary = DB::table('property_reviews')
                ->select(
                    'property_id',
                    DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                    DB::raw('COUNT(*) as total_reviews')
                )
                ->groupBy('property_id');

            $query = DB::table('properties')
                ->leftJoin('owners', 'owners.id', '=', 'properties.owner_id')
                ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                    $join->on('review_summary.property_id', '=', 'properties.id');
                })
                ->select(
                    'properties.*',
                    'owners.owner_verification_status',
                    DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
                    DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews'),
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
            $this->attachPropertyFeatureChips($properties->getCollection());

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


    // This is for editing property
    // For Owner
    public function editProperty(int $id){
        try {
            $userId = Auth::id();
            
            // 1. Get owner ID
            $ownerId = DB::table("owners")->where("user_id", $userId)->value("id");

            // 2. Fetch the main property data (one row)
            $property = DB::table("properties")
                ->join("property_types", "properties.property_type_id", "=", "property_types.id")
                ->select("properties.*", "property_types.type_name")
                ->where("properties.id", $id)
                ->where("properties.owner_id", $ownerId)
                ->first();

            if (!$property) {
                return response()->json(['message' => 'Property not found or unauthorized'], 404);
            }

            // Format the thumbnail URL in PHP (More readable and easier to debug)
            $property->thumbnail_url = $property->thumbnail 
                ? asset('storage/' . $property->thumbnail) 
                : null;
            $property->business_permit_url = $property->business_permit_path
                ? asset('storage/' . $property->business_permit_path)
                : null;

            // 3. Fetch related data as clean arrays for your Vue checkboxes
            $property->amenities = DB::table("property_amenities")
                ->where("property_id", $id)->pluck("amenity_id");
            $property->custom_amenities = DB::table("custom_amenities")
                ->where("property_id", $id)->pluck("custom_amenity");

            $property->facilities = DB::table("property_facilities")
                ->where("property_id", $id)->pluck("facility_id");
            $property->custom_facilities = DB::table("custom_facilities")
                ->where("property_id", $id)->pluck("custom_facility");

            $property->utilities = DB::table("utilities")
                ->where("property_id", $id)->pluck("utility_name");
            $property->custom_utilities = DB::table("custom_utilities")
                ->where("property_id", $id)->pluck("custom_utility");

            // 4. Handle Images and format URLs
            $images = DB::table("property_images")
                ->where("property_id", $id)
                ->pluck("img_path");

            $property->images = $images->map(function ($path) {
                return asset('storage/' . $path);
            });

            return response()->json([
                'status' => 'success',
                'property' => $property
            ]);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function updateProperty(Request $request, int $id){
        $subscriptionGuard = $this->ensureOwnerCanManageProperties();
        if ($subscriptionGuard) {
            return $subscriptionGuard;
        }

        DB::beginTransaction();
        try {
            $userId = Auth::id();
            $ownerId = DB::table("owners")->where("user_id", $userId)->value("id");

            // 1. Find and Verify Ownership
            $property = DB::table("properties")->where('id', $id)->where('owner_id', $ownerId)->first();
            if (!$property) {
                return response()->json(['message' => 'Unauthorized or Not Found'], 403);
            }
            
            // 2. Handle Thumbnail Update
            $thumbnailPath = $property->thumbnail; // Keep old by default
            if ($request->hasFile('thumbnail')) {
                if ($property->thumbnail) {
                    Storage::disk('public')->delete($property->thumbnail);
                }
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'public');
            }

            $request->validate([
                'business_permit' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            ]);

            $businessPermitPath = $property->business_permit_path;
            $businessPermitUploadedAt = $property->business_permit_uploaded_at;
            if ($request->hasFile('business_permit')) {
                if ($property->business_permit_path) {
                    Storage::disk('public')->delete($property->business_permit_path);
                }
                $businessPermitPath = $request->file('business_permit')->store('property_permits', 'public');
                $businessPermitUploadedAt = now();
            }

            // 3. Handle Gallery Images (Only if new ones provided)
            if ($request->hasFile('images')) {
                $oldImages = DB::table("property_images")->where("property_id", $id)->get();
                foreach ($oldImages as $oldImg) {
                    Storage::disk('public')->delete($oldImg->img_path);
                }
                DB::table("property_images")->where("property_id", $id)->delete();

                foreach ($request->file('images') as $image) {
                    DB::table("property_images")->insert([
                        'property_id' => $id,
                        'img_path' => $image->store('property_images', 'public'),
                        'created_at' => now()
                    ]);
                }
            }

            // 4. Update Bridge Tables (Delete & Replace)
            $syncTables = [
                'property_amenities' => ['col' => 'amenity_id', 'data' => $request->property_amenities],
                'custom_amenities'   => ['col' => 'custom_amenity', 'data' => $request->custom_amenities],
                'utilities'          => ['col' => 'utility_name', 'data' => $request->utilities],
                'custom_utilities'   => ['col' => 'custom_utility', 'data' => $request->custom_utilities],
                'property_facilities' => ['col' => 'facility_id', 'data' => $request->property_facilities],
                'custom_facilities'  => ['col' => 'custom_facility', 'data' => $request->custom_facilities],
            ];

            foreach ($syncTables as $table => $config) {
                DB::table($table)->where("property_id", $id)->delete();
                if (!empty($config['data'])) {
                    foreach ($config['data'] as $value) {
                        DB::table($table)->insert(['property_id' => $id, $config['col'] => $value]);
                    }
                }
            }

            $data = [
                // From Step 1
                'title' => $request->title ?? $property->title,
                'description' => $request->description ?? $property->description,
                'thumbnail' => $thumbnailPath,
                'business_permit_path' => $businessPermitPath,
                'business_permit_uploaded_at' => $businessPermitUploadedAt,

                // From Step 2
                'agreement_type' => $request->agreement_type  ?? $property->agreement_type,
                'property_type_id' => $request->property_type_id  ?? $property->property_type_id,
                "furnishing" => $request->furnishing  ?? $property->furnishing,
                // For Commercial Space
                "floor_area" => $request->floor_area  ?? $property->floor_area,
                "lot_area" => $request->lot_area  ?? $property->lot_area,
                "max_size" => $request->max_size  ?? $property->max_size,
                // For Types Excluding Commercial Space
                "bedrooms" => $request->bedrooms  ?? $property->bedrooms,
                "single_bed" => $request->single_bed  ?? $property->single_bed,
                "double_bed" => $request->double_bed  ?? $property->double_bed,
                
                "public_bath" => $request->public_bath  ?? $property->public_bath,
                "private_bath" => $request->private_bath  ?? $property->private_bath,
                
                "bed_space" => $request->bed_space  ?? $property->bed_space,
                // Others
                "rules" => $request->rules  ?? $property->rules,
                "curfew_from" => $request->curfew_from  ?? $property->curfew_from,
                "curfew_to" => $request->curfew_to  ?? $property->curfew_to,

                // Step 3
                'payment_frequency' => $request->payment_frequency ?? $property->payment_frequency,
                'price' => $request->price ?? $property->price,
                'advance_payment_months' => $request->advance_payment_months ?? $property->advance_payment_months,
                'deposit_required' => $request->deposit_required ?? $property->deposit_required,
                
                // Step 4
                'latitude' => $request->latitude ?? $property->latitude,
                'longitude' => $request->longitude ?? $property->longitude,
                'region_name' => $request->region_name ?? $property->region_name,
                'state_name' => $request->state_name ?? $property->state_name,
                'town_name' => $request->town_name ?? $property->town_name,
                'village_name' => $request->village_name ?? $property->village_name,
                'status' => "pending",
                'updated_at' => now(),
            ];

            // 5. Update the Main Property Table
            DB::table("properties")->where('id', $id)->update($data);

            DB::commit();
            return response()->json(['message' => 'Property updated successfully']);

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()], 500);
        }
    }

    private function ensureOwnerCanManageProperties()
    {
        $snapshot = $this->subscriptionLifecycleService->getOwnerSubscriptionSnapshotByUserId(Auth::id());
        if (!($snapshot['can_manage_properties'] ?? false)) {
            return response()->json([
                'message' => 'Subscription expired or inactive. Renew to manage properties.',
                'subscription' => $snapshot,
            ], 403);
        }

        return null;
    }

    private function ensureOwnerHasListingSlot()
    {
        $owner = DB::table('owners')->where('user_id', Auth::id())->first();
        if (!$owner) {
            return response()->json([
                'message' => 'Owner profile not found.',
            ], 404);
        }

        $activeSubscription = DB::table('subscriptions')
            ->where('owner_id', $owner->id)
            ->where('status', 'active')
            ->whereDate('end_date', '>=', now()->toDateString())
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();

        if (!$activeSubscription) {
            return response()->json([
                'message' => 'No active subscription found.',
            ], 403);
        }

        $propertyCount = DB::table('properties')
            ->where('owner_id', $owner->id)
            ->count();

        if ($propertyCount >= (int) $activeSubscription->listing_limit) {
            return response()->json([
                'message' => 'Listing limit reached for your current subscription plan.',
                'listing_limit' => (int) $activeSubscription->listing_limit,
                'current_listings' => $propertyCount,
            ], 403);
        }

        return null;
    }



}

