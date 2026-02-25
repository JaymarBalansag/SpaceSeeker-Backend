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
}
