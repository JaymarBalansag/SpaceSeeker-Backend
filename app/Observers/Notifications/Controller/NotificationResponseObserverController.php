<?php

namespace App\Observers\Notifications\Controller;

use Illuminate\Notifications\DatabaseNotification;

class NotificationResponseObserverController
{
    public function toArray(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['event_type'] ?? 'generic',
            'title' => $data['title'] ?? 'You have a new notification.',
            'message' => $data['message'] ?? null,
            'event_type' => $data['event_type'] ?? 'generic',
            'tab' => $data['tab'] ?? 'system',
            'sender_name' => $data['sender_name'] ?? null,
            'tenant_name' => $data['tenant_name'] ?? null,
            'property_name' => $data['property_name'] ?? null,
            'reviewed_by' => $data['reviewed_by'] ?? null,
            'verification_status' => $data['verification_status'] ?? null,
            'metadata' => $data,
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'is_read' => !is_null($notification->read_at),
        ];
    }
}
