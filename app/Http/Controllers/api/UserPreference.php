<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class UserPreference extends Controller
{
    public function getUserPreferences()
    {
        $userId = Auth::id();

        $amenities = DB::table('preferred_amenities')
            ->join('amenities', 'preferred_amenities.amenity_id', '=', 'amenities.id')
            ->where('preferred_amenities.user_id', $userId)
            ->pluck('amenities.amenity_name');

        $propertyTypes = DB::table('preferred_properties')
            ->join('property_types', 'preferred_properties.property_type_id', '=', 'property_types.id')
            ->where('preferred_properties.user_id', $userId)
            ->pluck('property_types.type_name');

        return response()->json([
            'amenities' => $amenities,
            'property_types' => $propertyTypes,
        ]);
    }

    public function getUserPreferencesForEdit()
    {
        $userId = Auth::id();

        return response()->json([
            "amenities" => DB::table('preferred_amenities')
                ->where('user_id', $userId)
                ->get(['amenity_id']),

            "properties" => DB::table('preferred_properties')
                ->where('user_id', $userId)
                ->get(['property_type_id']),
        ]);
    }


    public function updateUserPreferences(Request $request)
    {
        try {
            $userId = Auth::id();

            $amenities = $request->input('amenities', []);
            $properties = $request->input('property_types', []);

            DB::transaction(function () use ($userId, $amenities, $properties) {

                DB::table('preferred_amenities')
                    ->where('user_id', $userId)
                    ->delete();

                DB::table('preferred_properties')
                    ->where('user_id', $userId)
                    ->delete();

                foreach ($amenities as $id) {
                    DB::table('preferred_amenities')->insert([
                        'user_id' => $userId,
                        'amenity_id' => $id
                    ]);
                }

                foreach ($properties as $id) {
                    DB::table('preferred_properties')->insert([
                        'user_id' => $userId,
                        'property_type_id' => $id
                    ]);
                }
            });

            return response()->json([
                "status" => "success",
                "message" => "User preferences updated successfully."
            ]);

        } catch (\Throwable $th) {
            logger()->error("Preferences update error: " . $th->getMessage());

            return response()->json([
                "status" => "error",
                "message" => "Failed to update preferences."
            ], 500);
        }
    }
}
