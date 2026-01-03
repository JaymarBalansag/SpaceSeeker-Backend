<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecommendedController extends Controller
{

    private function getProperty(){
        try {
            $properties = DB::table('properties')
                ->select('properties.*',
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
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

            $properties = DB::table('properties')
                ->select('properties.*',
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
                ->where("properties.town_name", $user->town_name)
                ->inRandomOrder()
                ->limit(6)
                ->get();

            return response()->json([
                'status' => 'success',
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

            $properties = DB::table('properties')
                ->join('property_amenities', 'property_amenities.property_id', '=', 'properties.id')
                ->whereIn('property_amenities.amenity_id', $preferredAmenityIds)
                ->select('properties.*', 
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
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

            $preferredTypeIds = DB::table('preffered_properties')
                ->where('user_id', $user->id)
                ->pluck('property_type_id');

            $properties = DB::table('properties')
                ->whereIn('properties.property_type_id', $preferredTypeIds)
                ->select('properties.*',
                DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
                ->distinct()
                ->inRandomOrder()
                ->limit(6)
                ->get();

            if(!$properties && $properties->isEmpty()){
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No recommended properties found based on preferred property types.'
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

    public function byNearYou(){
        try {
            $user = DB::table('users')->where('id', Auth::id())->first();


            $lat = (float) $user->latitude;
            $lng = (float) $user->longitude;
            $radius = 10; // km

            

            $properties = DB::table('properties')
                ->select(
                    'properties.*',
                    DB::raw("
                        (
                            6371 * acos(
                                cos(radians($lat)) *
                                cos(radians(properties.latitude)) *
                                cos(radians(properties.longitude) - radians($lng)) +
                                sin(radians($lat)) *
                                sin(radians(properties.latitude))
                            )
                        ) AS distance
                    "),
                    DB::raw("
                        CASE 
                            WHEN properties.thumbnail IS NOT NULL 
                            THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) 
                            ELSE NULL 
                        END AS image_url
                    ")
                )
                ->where('properties.town_name', $user->town_name)
                ->having('distance', '<=', $radius)
                ->orderBy('distance')
                ->limit(6)
                ->get();

            if ($properties->isEmpty()) {
                $properties = DB::table('properties')
                    ->select('properties.*',
                        DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                    )
                    ->where('properties.town_name', $user->town_name)
                    ->inRandomOrder()
                    ->limit(6)
                    ->get();
            }

            if ($properties->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'No nearby properties found.'
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'data' => $properties,
                'message' => 'Nearby properties retrieved successfully.'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to fetch nearby properties'], 500);
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
            // return response()->json([
            //     'status' => 'error',
            //     'message' => 'Failed to fetch popular property types.'
            // ], 500);
            return response()->json([
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ], 500);
        }
    }

}
