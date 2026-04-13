<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Observers\Notifications\Controller\NotificationRequestObserverController;
use App\Observers\Notifications\Controller\NotificationResponseObserverController;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationRequestObserverController $requestObserver,
        protected NotificationResponseObserverController $responseObserver
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $limit = $this->requestObserver->resolveLimit($request);
        $tabType = $this->requestObserver->resolveType($request->query('type'));

        $query = $user->notifications()->orderByDesc('created_at');
        if ($tabType) {
            $query->where('data->tab', $tabType);
        }

        $notifications = $query->limit($limit)->get();
        $rows = $notifications
            ->map(fn (DatabaseNotification $n) => $this->responseObserver->toArray($n))
            ->values();

        return response()->json([
            'notifications' => $rows,
        ], 200);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
        ], 200);
    }

    public function markAllAsRead(Request $request)
    {
        $tabType = $this->requestObserver->resolveType($request->input('type'));

        $query = $request->user()
            ->unreadNotifications();

        if ($tabType) {
            $query->where('data->tab', $tabType);
        }

        $updated = $query->update([
            'read_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notifications marked as read.',
            'updated' => $updated,
        ], 200);
    }

    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully.',
            'deleted_id' => $id,
        ], 200);
    }

    public function destroyMany(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|string',
        ]);

        $ids = collect($validated['ids'])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json([
                'message' => 'No valid notification ids were provided.',
            ], 422);
        }

        $query = $request->user()->notifications()->whereIn('id', $ids->all());
        $deletedIds = $query->pluck('id')->values();
        $deletedCount = $query->delete();

        return response()->json([
            'message' => 'Selected notifications deleted successfully.',
            'deleted_count' => $deletedCount,
            'deleted_ids' => $deletedIds,
        ], 200);
    }

    public function destroyAllByTab(Request $request)
    {
        $tabType = $this->requestObserver->resolveType($request->input('type'));
        if (!$tabType) {
            return response()->json([
                'message' => 'Invalid notification type. Use system or bookings.',
            ], 422);
        }

        $query = $request->user()->notifications()->where('data->tab', $tabType);
        $deletedCount = $query->delete();

        return response()->json([
            'message' => 'Notifications deleted successfully.',
            'type' => $tabType,
            'deleted_count' => $deletedCount,
        ], 200);
    }
}
