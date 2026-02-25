<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\User;
use Illuminate\Support\Str;

class NotificationService
{
    public function createForUser(int $recipientUserId, array $payload): ?array
    {
        $recipient = User::query()->find($recipientUserId);
        if (!$recipient) {
            return null;
        }

        $notification = $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $payload['event_type'] ?? 'system',
            'data' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $responsePayload = [
            'id' => $notification->id,
            'type' => $payload['event_type'] ?? 'generic',
            'event_type' => $payload['event_type'] ?? 'generic',
            'title' => $payload['title'] ?? 'You have a new notification.',
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'read_at' => null,
            'is_read' => false,
            'metadata' => $payload,
        ];

        broadcast(new NotificationCreated($recipientUserId, $responsePayload))->toOthers();

        return $responsePayload;
    }
}
