<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PropertyBookmarkController extends Controller
{
    private function ensureBookmarkingUser()
    {
        $user = Auth::user();

        if (!$user) {
            return [null, response()->json([
                'message' => 'Unauthorized.',
            ], 401)];
        }

        if (in_array(strtolower((string) $user->role), ['owner', 'admin'], true)) {
            return [$user, response()->json([
                'message' => 'Only renter-side users can bookmark properties.',
            ], 403)];
        }

        return [$user, null];
    }

    private function propertyQuery()
    {
        return DB::table('properties')
            ->leftJoin('owners', 'owners.id', '=', 'properties.owner_id')
            ->leftJoin('users as owner_users', 'owners.user_id', '=', 'owner_users.id');
    }

    public function index()
    {
        try {
            [$user, $errorResponse] = $this->ensureBookmarkingUser();
            if ($errorResponse) {
                return $errorResponse;
            }

            $bookmarks = DB::table('bookmarked_properties')
                ->join('properties', 'bookmarked_properties.property_id', '=', 'properties.id')
                ->leftJoin('owners', 'owners.id', '=', 'properties.owner_id')
                ->leftJoin('users as owner_users', 'owners.user_id', '=', 'owner_users.id')
                ->select(
                    'bookmarked_properties.id as bookmark_id',
                    'bookmarked_properties.created_at as bookmarked_at',
                    'properties.id',
                    'properties.title',
                    'properties.description',
                    'properties.price',
                    'properties.payment_frequency',
                    'properties.agreement_type',
                    'properties.state_name',
                    'properties.town_name',
                    'properties.latitude',
                    'properties.longitude',
                    'properties.is_available',
                    'properties.status',
                    'owners.owner_verification_status',
                    DB::raw("TRIM(CONCAT(COALESCE(owner_users.first_name, ''), ' ', COALESCE(owner_users.last_name, ''))) as owner_name"),
                    DB::raw("CASE WHEN properties.thumbnail IS NOT NULL THEN CONCAT('" . asset('storage') . "/', properties.thumbnail) ELSE NULL END as image_url")
                )
                ->where('bookmarked_properties.user_id', $user->id)
                ->orderByDesc('bookmarked_properties.created_at')
                ->get();

            return response()->json([
                'message' => 'Bookmarked properties retrieved successfully.',
                'data' => $bookmarks,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to load bookmarked properties.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function bookmarkedPropertyIds()
    {
        try {
            [$user, $errorResponse] = $this->ensureBookmarkingUser();
            if ($errorResponse) {
                return $errorResponse;
            }

            $propertyIds = DB::table('bookmarked_properties')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->pluck('property_id')
                ->map(fn ($id) => (int) $id)
                ->values();

            return response()->json([
                'message' => 'Bookmarked property ids retrieved successfully.',
                'data' => $propertyIds,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to load bookmarked property ids.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function store(int $id)
    {
        try {
            [$user, $errorResponse] = $this->ensureBookmarkingUser();
            if ($errorResponse) {
                return $errorResponse;
            }

            $property = $this->propertyQuery()
                ->where('properties.id', $id)
                ->select(
                    'properties.id',
                    'properties.title',
                    'owner_users.id as owner_user_id'
                )
                ->first();

            if (!$property) {
                return response()->json([
                    'message' => 'Property not found.',
                ], 404);
            }

            if ((int) $property->owner_user_id === (int) $user->id) {
                return response()->json([
                    'message' => 'You cannot bookmark your own property.',
                ], 422);
            }

            $existing = DB::table('bookmarked_properties')
                ->where('user_id', $user->id)
                ->where('property_id', $property->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Property is already in your bookmarks.',
                    'bookmarked' => true,
                ], 200);
            }

            $now = now();
            DB::table('bookmarked_properties')->insert([
                'user_id' => $user->id,
                'property_id' => $property->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json([
                'message' => 'Property bookmarked successfully.',
                'bookmarked' => true,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to bookmark property.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            [$user, $errorResponse] = $this->ensureBookmarkingUser();
            if ($errorResponse) {
                return $errorResponse;
            }

            $deleted = DB::table('bookmarked_properties')
                ->where('user_id', $user->id)
                ->where('property_id', $id)
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'message' => 'Bookmark not found.',
                    'bookmarked' => false,
                ], 404);
            }

            return response()->json([
                'message' => 'Property removed from bookmarks.',
                'bookmarked' => false,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to remove bookmark.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
