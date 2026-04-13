<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PropertyReportController extends Controller
{
    private array $allowedReasons = [
        'misleading_information',
        'fake_or_scam_listing',
        'incorrect_price_or_details',
        'inappropriate_photos_or_content',
        'unavailable_but_still_advertised',
        'safety_concern',
        'other',
    ];

    public function store(Request $request, int $id)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized.',
                ], 401);
            }

            if (in_array(strtolower((string) $user->role), ['owner', 'admin'], true)) {
                return response()->json([
                    'message' => 'Only renter-side users can report properties.',
                ], 403);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'in:' . implode(',', $this->allowedReasons)],
                'details' => ['nullable', 'string', 'max:1000'],
            ]);

            $property = DB::table('properties')
                ->join('owners', 'properties.owner_id', '=', 'owners.id')
                ->join('users as owner_users', 'owners.user_id', '=', 'owner_users.id')
                ->where('properties.id', $id)
                ->select(
                    'properties.id as property_id',
                    'properties.title as property_title',
                    'owners.id as owner_id',
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
                    'message' => 'You cannot report your own property.',
                ], 422);
            }

            $now = now();
            $activeStatuses = ['pending', 'under_review'];
            $existingReport = DB::table('property_reports')
                ->where('property_id', $property->property_id)
                ->where('reporter_user_id', $user->id)
                ->whereIn('status', $activeStatuses)
                ->first();

            if ($existingReport) {
                DB::table('property_reports')
                    ->where('id', $existingReport->id)
                    ->update([
                        'reason' => $validated['reason'],
                        'details' => $validated['details'] ?? null,
                        'updated_at' => $now,
                    ]);

                return response()->json([
                    'message' => 'Your existing active report was updated and sent back to the admin queue.',
                    'report_id' => $existingReport->id,
                    'updated_existing' => true,
                ], 200);
            }

            $reportId = DB::table('property_reports')->insertGetId([
                'property_id' => $property->property_id,
                'reporter_user_id' => $user->id,
                'owner_id' => $property->owner_id,
                'reason' => $validated['reason'],
                'details' => $validated['details'] ?? null,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json([
                'message' => 'Property report submitted successfully.',
                'report_id' => $reportId,
                'updated_existing' => false,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to submit property report.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}