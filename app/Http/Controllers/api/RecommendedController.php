<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use function PHPUnit\Framework\isEmpty;

class RecommendedController extends Controller
{
    private function reviewSummarySubquery()
    {
        return DB::table('property_reviews')
            ->select(
                'property_id',
                DB::raw('ROUND(AVG(rating), 1) as average_rating'),
                DB::raw('COUNT(*) as total_reviews')
            )
            ->groupBy('property_id');
    }

    private function basePropertiesQuery()
    {
        $reviewSummary = $this->reviewSummarySubquery();

        return DB::table('properties')
            ->leftJoinSub($reviewSummary, 'review_summary', function ($join) {
                $join->on('review_summary.property_id', '=', 'properties.id');
            })
            ->select(
                'properties.*',
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url"),
                DB::raw('COALESCE(review_summary.average_rating, 0) as average_rating'),
                DB::raw('COALESCE(review_summary.total_reviews, 0) as total_reviews')
            );
    }

    private function getProperty(){
        try {
            $properties = $this->basePropertiesQuery()
                ->where("status", "=", "active")
                ->inRandomOrder()
                ->limit(6)
                ->get();

            if($properties->isEmpty()){
                return [];
            }

            return $properties;
        } catch (\Throwable $th) {
            return [];
        }
    }

    // The Recommended Section
    public function byDefault() {
        try {

            $user = Auth::user();

            if(!$user){

                $property = $this->getProperty();

                return response()->json([
                    'status' => 'success',
                    'data' => $property,
                    'message' => $property ? 'Complete your profile to get personalized recommendations.' : "No properties available at the moment."
                ], 200);
            }

            $properties = $this->basePropertiesQuery()
                ->where("properties.town_name", $user->town_name)
                ->where("status", "=", "active")
                ->inRandomOrder()
                ->limit(6)
                ->get();

            if ($properties->isEmpty()) {
                $properties = $this->getProperty();
            }

            return response()->json([
                'status' => 'success',
                'user' => $user,
                'data' => $properties,
                'message' => 'Recommended properties retrieved successfully.'
            ], 200);
                
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching recommended properties: ' . $th->getMessage()
            ], 500);
        }
    }
    public function byPreferredAmenities(){
        try {
            $user = Auth::user();

            $preferredAmenityIds = DB::table('preferred_amenities')
                ->where('user_id', $user->id)
                ->pluck('amenity_id');

            $properties = $this->basePropertiesQuery()
                ->join('property_amenities', 'property_amenities.property_id', '=', 'properties.id')
                ->whereIn('property_amenities.amenity_id', $preferredAmenityIds)
                ->where("properties.status", "=", "active")
                ->distinct()
                ->inRandomOrder()
                ->limit(6)
                ->get();

            if($properties->isEmpty()){
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No preferences set'
                ], 200);
            } 

            return response()->json([
                'status' => 'success',
                'data' => $properties,
                'message' => 'Recommended properties based on preferred amenities retrieved successfully.'
            ], 200);
                
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching recommended properties: ' . $th->getMessage()
            ], 500);
        }
    }

    public function byPrefferedTypes(){
        try {
            $user = Auth::user();

            $preferredTypeIds = DB::table('preferred_properties')
                ->where('user_id', $user->id)
                ->pluck('property_type_id');

            $properties = $this->basePropertiesQuery()
                ->whereIn('properties.property_type_id', $preferredTypeIds)
                ->where("properties.status", "=", "active")
                ->distinct()
                ->inRandomOrder()
                ->limit(6)
                ->get();

            if ($properties->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No preference set'
                ], 200);
            } 

            return response()->json([
                'status' => 'success',
                'data' => $properties,
                'message' => 'Recommended properties based on preferred property types retrieved successfully.'
            ], 200);
                
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching recommended properties: ' . $th->getMessage()
            ], 500);
        }
    }

    public function byNearYou() {
        try {
            $user = DB::table('users')->where('id', Auth::id())->first();
            if (!$user || !$user->latitude || !$user->longitude) {
                return response()->json(['error' => 'User location not found', 'data' => []], 200);
            }

            $lat = (float) $user->latitude;
            $lng = (float) $user->longitude;
            $radius = 10; // km

            // Optimization: Bounding Box (1 degree is approx 111km)
            // This allows the database to use indexes on lat/lng columns
            $latLimit = $radius / 111.045;
            $lngLimit = $radius / (111.045 * cos(deg2rad($lat)));

            $query = $this->basePropertiesQuery()
                ->addSelect(DB::raw("
                    (6371 * acos(
                        cos(radians($lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians($lng)) + 
                        sin(radians($lat)) * sin(radians(latitude))
                    )) AS distance
                "))
                ->where('status', 'active')
                // Bounding box filter (fast indexed search)
                ->whereBetween('latitude', [$lat - $latLimit, $lat + $latLimit])
                ->whereBetween('longitude', [$lng - $lngLimit, $lng + $lngLimit]);

            // Get properties within exact radius
            $properties = (clone $query)
                ->having('distance', '<=', $radius)
                ->orderBy('distance')
                ->limit(6)
                ->get();

            // Fallback: If nothing nearby, show properties in the same town
            // if ($properties->isEmpty()) {
            //     $properties = DB::table('properties')
            //         ->select('properties.*')
            //         ->addSelect(DB::raw("CASE WHEN thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', thumbnail) ELSE NULL END as image_url"))
            //         ->where('town_name', $user->town_name)
            //         ->where('status', 'active')
            //         ->inRandomOrder()
            //         ->limit(6)
            //         ->get();
            // }

            return response()->json([
                'status' => 'success',
                'data' => $properties,
                'message' => $properties->isEmpty() ? 'No properties found.' : 'Properties retrieved.'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => 'Search failed: ' . $th->getMessage()], 500);
        }
    }

    // End of Recommended Section

    // The Recent Properties Section
    public function recentProperties() {
        try {
            $properties = DB::table('properties')
                ->select('properties.*',
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
                ->where("properties.status", "=", "active")
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            if(!$properties && $properties->isEmpty()){
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No recent properties found.'
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'data' => $properties,
                'message' => 'Recent properties retrieved successfully.'
            ], 200);


        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching recent properties: ' . $th->getMessage()
            ], 500);
        }
    }

    public function byPopularTypes(){
        try {
            $types = DB::table('property_types')
                ->leftJoin('properties', 'properties.property_type_id', '=', 'property_types.id')
                ->select(
                    'property_types.id',
                    'property_types.type_name',
                    DB::raw('COUNT(properties.id) as total')
                )
                ->where("properties.status", "=", "active")
                ->groupBy('property_types.id', 'property_types.type_name')
                ->orderByDesc('total')
                ->limit(4)
                ->get();

            if($types->isEmpty()){
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No popular property types found.'
                ]);
            }

            return response()->json([
                'status' => 'success',
                'data' => $types,
                'message' => 'Popular property types retrieved successfully.'
            ]);

        } catch (\Throwable $th) {

            return response()->json([
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ], 500);
        }
    }

    public function byTrending(Request $request) {
        try {
            $limit = (int) $request->query('limit', 2);
            $limit = max(1, min($limit, 20));

            $properties = $this->basePropertiesQuery()
                ->where('properties.status', 'active')
                ->orderByDesc('properties.views')
                ->orderByDesc('properties.created_at')
                ->limit($limit)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $properties,
                'message' => $properties->isEmpty()
                    ? 'No trending properties found.'
                    : 'Trending properties retrieved successfully.'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching trending properties: ' . $th->getMessage()
            ], 500);
        }
    }

}
