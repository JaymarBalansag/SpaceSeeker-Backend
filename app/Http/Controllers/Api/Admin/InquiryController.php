<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InquiryController extends Controller
{
    public function index(Request $request)
    {
        try {
            $status = strtolower(trim((string) $request->query('status', 'all')));
            $search = trim((string) $request->query('search', ''));

            $allowedStatuses = ['all', 'unread', 'resolved'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'all';
            }

            $query = Inquiry::query()->orderByDesc('created_at');

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            if ($search !== '') {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('message', 'like', '%' . $search . '%');
                });
            }

            return response()->json([
                'message' => 'Admin inquiries retrieved successfully.',
                'data' => $query->get()->map(function (Inquiry $inquiry) {
                    return [
                        'id' => $inquiry->id,
                        'name' => $inquiry->name,
                        'email' => $inquiry->email,
                        'message' => $inquiry->message,
                        'message_preview' => str($inquiry->message)->limit(120)->value(),
                        'status' => $inquiry->status,
                        'resolved_at' => optional($inquiry->resolved_at)->toDateTimeString(),
                        'created_at' => optional($inquiry->created_at)->toDateTimeString(),
                    ];
                }),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to load inquiries.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $inquiry = Inquiry::find($id);

            if (!$inquiry) {
                return response()->json([
                    'message' => 'Inquiry not found.',
                ], 404);
            }

            return response()->json([
                'message' => 'Inquiry retrieved successfully.',
                'data' => $inquiry,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to load inquiry details.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function resolve(int $id)
    {
        try {
            $inquiry = Inquiry::find($id);

            if (!$inquiry) {
                return response()->json([
                    'message' => 'Inquiry not found.',
                ], 404);
            }

            if ($inquiry->status === 'resolved') {
                return response()->json([
                    'message' => 'Inquiry is already resolved.',
                ], 422);
            }

            $inquiry->update([
                'status' => 'resolved',
                'resolved_at' => now(),
            ]);

            return response()->json([
                'message' => 'Inquiry resolved successfully.',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to resolve inquiry.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function destroyMany(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['integer', 'distinct', 'exists:inquiries,id'],
            ]);

            $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

            $deleted = Inquiry::query()
                ->whereIn('id', $ids)
                ->delete();

            return response()->json([
                'message' => $deleted === 1
                    ? 'Inquiry deleted successfully.'
                    : 'Selected inquiries deleted successfully.',
                'deleted' => $deleted,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to delete inquiries.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
