<?php

namespace App\Http\Controllers\Api\Admin\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PropertyReportController extends Controller
{
    private array $allowedStatuses = ['pending', 'under_review', 'resolved', 'dismissed'];

    private array $allowedReasons = [
        'misleading_information',
        'fake_or_scam_listing',
        'incorrect_price_or_details',
        'inappropriate_photos_or_content',
        'unavailable_but_still_advertised',
        'safety_concern',
        'other',
    ];
    private function normalizeStatusFilter(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, array_merge(['all'], $this->allowedStatuses), true)
            ? $normalized
            : 'all';
    }

    private function normalizeReasonFilter(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, array_merge(['all'], $this->allowedReasons), true)
            ? $normalized
            : 'all';
    }
    private function baseQuery()
    {
        return DB::table('property_reports')
            ->join('properties', 'property_reports.property_id', '=', 'properties.id')
            ->join('owners', 'property_reports.owner_id', '=', 'owners.id')
            ->join('users as owner_users', 'owners.user_id', '=', 'owner_users.id')
            ->join('users as reporter_users', 'property_reports.reporter_user_id', '=', 'reporter_users.id')
            ->leftJoin('users as reviewer_users', 'property_reports.reviewed_by', '=', 'reviewer_users.id')
            ->select(
                'property_reports.*',
                'properties.title as property_title',
                'properties.status as property_status',
                'properties.is_available as property_is_available',
                'owner_users.first_name as owner_first_name',
                'owner_users.last_name as owner_last_name',
                'owner_users.email as owner_email',
                'reporter_users.first_name as reporter_first_name',
                'reporter_users.last_name as reporter_last_name',
                'reporter_users.email as reporter_email',
                'reviewer_users.first_name as reviewer_first_name',
                'reviewer_users.last_name as reviewer_last_name'
            );
    }

    private function transformReport(object $report): array
    {
        $ownerName = trim(($report->owner_first_name ?? '') . ' ' . ($report->owner_last_name ?? ''));
        $reporterName = trim(($report->reporter_first_name ?? '') . ' ' . ($report->reporter_last_name ?? ''));
        $reviewerName = trim(($report->reviewer_first_name ?? '') . ' ' . ($report->reviewer_last_name ?? ''));

        return [
            'id' => $report->id,
            'property_id' => $report->property_id,
            'property_title' => $report->property_title,
            'property_status' => $report->property_status,
            'property_is_available' => (bool) $report->property_is_available,
            'owner_id' => $report->owner_id,
            'owner_name' => $ownerName !== '' ? $ownerName : null,
            'owner_email' => $report->owner_email,
            'reporter_user_id' => $report->reporter_user_id,
            'reporter_name' => $reporterName !== '' ? $reporterName : null,
            'reporter_email' => $report->reporter_email,
            'reason' => $report->reason,
            'details' => $report->details,
            'status' => $report->status,
            'admin_notes' => $report->admin_notes,
            'reviewed_at' => $report->reviewed_at,
            'reviewed_by' => $report->reviewed_by,
            'reviewed_by_name' => $reviewerName !== '' ? $reviewerName : null,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];
    }

    public function index(Request $request)
    {
        try {
            $status = $this->normalizeStatusFilter((string) $request->query('status', 'all'));
            $reason = $this->normalizeReasonFilter((string) $request->query('reason', 'all'));
            $search = trim((string) $request->query('search', ''));

            $query = $this->baseQuery()->orderByDesc('property_reports.created_at');

            if (in_array($status, $this->allowedStatuses, true)) {
                $query->where('property_reports.status', $status);
            }

            if (in_array($reason, $this->allowedReasons, true)) {
                $query->where('property_reports.reason', $reason);
            }

            if ($search !== '') {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('properties.title', 'like', '%' . $search . '%')
                        ->orWhere('owner_users.first_name', 'like', '%' . $search . '%')
                        ->orWhere('owner_users.last_name', 'like', '%' . $search . '%')
                        ->orWhere('owner_users.email', 'like', '%' . $search . '%')
                        ->orWhere('reporter_users.first_name', 'like', '%' . $search . '%')
                        ->orWhere('reporter_users.last_name', 'like', '%' . $search . '%')
                        ->orWhere('reporter_users.email', 'like', '%' . $search . '%');
                });
            }

            $reports = $query->get()->map(fn ($report) => $this->transformReport($report))->values();

            return response()->json([
                'message' => 'Property reports retrieved successfully.',
                'data' => $reports,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to load property reports.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $report = $this->baseQuery()
                ->where('property_reports.id', $id)
                ->first();

            if (!$report) {
                return response()->json([
                    'message' => 'Property report not found.',
                ], 404);
            }

            return response()->json([
                'message' => 'Property report retrieved successfully.',
                'data' => $this->transformReport($report),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to load property report details.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $validated = $request->validate([
                'status' => ['required', 'string', 'in:' . implode(',', ['under_review', 'resolved', 'dismissed'])],
                'admin_notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $report = DB::table('property_reports')->where('id', $id)->first();

            if (!$report) {
                return response()->json([
                    'message' => 'Property report not found.',
                ], 404);
            }

            DB::table('property_reports')
                ->where('id', $id)
                ->update([
                    'status' => $validated['status'],
                    'admin_notes' => $validated['admin_notes'] ?? null,
                    'reviewed_at' => now(),
                    'reviewed_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

            $updated = $this->baseQuery()->where('property_reports.id', $id)->first();

            return response()->json([
                'message' => 'Property report updated successfully.',
                'data' => $updated ? $this->transformReport($updated) : null,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to update property report.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}