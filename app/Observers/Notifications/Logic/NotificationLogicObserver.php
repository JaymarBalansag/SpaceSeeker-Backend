<?php

namespace App\Observers\Notifications\Logic;

class NotificationLogicObserver
{
    public function buildMessagePayload(string $senderName, ?int $conversationId = null): array
    {
        return [
            'event_type' => 'message_received',
            'title' => $senderName . ' sent you a message.',
            'tab' => 'system',
            'sender_name' => $senderName,
            'conversation_id' => $conversationId,
        ];
    }

    public function buildTenantReviewPayload(string $tenantName, string $propertyName, int $rating): array
    {
        return [
            'event_type' => 'tenant_reviewed_property',
            'title' => $tenantName . ' left a review on ' . $propertyName . '.',
            'tab' => 'system',
            'tenant_name' => $tenantName,
            'property_name' => $propertyName,
            'rating' => $rating,
        ];
    }

    public function buildBookingPendingPayload(string $propertyName, string $requesterName, ?int $bookingId = null): array
    {
        return [
            'event_type' => 'booking_pending',
            'title' => 'You have a pending booking request for ' . $propertyName . '.',
            'tab' => 'bookings',
            'property_name' => $propertyName,
            'requester_name' => $requesterName,
            'booking_id' => $bookingId,
        ];
    }
}
