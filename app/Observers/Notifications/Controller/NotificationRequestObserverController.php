<?php

namespace App\Observers\Notifications\Controller;

use Illuminate\Http\Request;

class NotificationRequestObserverController
{
    public function resolveLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', 50);
        if ($limit < 1) {
            return 1;
        }

        return min($limit, 100);
    }

    public function resolveType(?string $rawType): ?string
    {
        if (!$rawType) {
            return null;
        }

        $type = strtolower(trim($rawType));
        if (!in_array($type, ['system', 'bookings'], true)) {
            return null;
        }

        return $type;
    }
}
