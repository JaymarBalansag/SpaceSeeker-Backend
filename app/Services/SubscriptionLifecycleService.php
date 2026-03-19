<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionLifecycleService
{
    public function syncStatuses(int $warningDays = 7): array
    {
        $now = now();
        $today = $now->toDateString();
        $warningUntil = $now->copy()->addDays($warningDays)->toDateString();

        $warningsMarked = DB::table('subscriptions')
            ->where('status', 'active')
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $warningUntil)
            ->where(function ($query) use ($today) {
                $query->whereNull('warning_sent_at')
                    ->orWhereDate('warning_sent_at', '<', $today);
            })
            ->update([
                'warning_sent_at' => $now,
                'updated_at' => $now,
            ]);

        $expiredSubscriptions = DB::table('subscriptions')
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $today)
            ->get();

        $expiredCount = 0;
        foreach ($expiredSubscriptions as $subscription) {
            if ($this->expireSubscriptionAndOwner((int) $subscription->id)) {
                $expiredCount++;
            }
        }

        Log::info('Subscription sync snapshot.', [
            'warning_days' => $warningDays,
            'warnings_marked' => $warningsMarked,
            'expired_processed' => $expiredCount,
        ]);

        return [
            'warnings_marked' => $warningsMarked,
            'expired_processed' => $expiredCount,
        ];
    }
    public function getOwnerSubscriptionSnapshotByUserId(int $userId, int $warningDays = 7): array
    {
        $owner = DB::table('owners')->where('user_id', $userId)->first();
        if (!$owner) {
            return [
                'can_manage_properties' => false,
                'status' => 'none',
                'owner_verification_status' => 'unverified',
                'owner_verified_at' => null,
                'listing_limit' => null,
                'amount' => null,
                'start_date' => null,
                'message' => 'Owner profile not found.',
            ];
        }

        $subscription = DB::table('subscriptions')
            ->where(function ($query) use ($owner, $userId) {
                $query->where('owner_id', $owner->id)
                    ->orWhere('user_id', $userId);
            })
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();

        if (!$subscription) {
            return [
                'can_manage_properties' => false,
                'status' => 'none',
                'owner_verification_status' => $owner->owner_verification_status ?? 'unverified',
                'owner_verified_at' => $owner->owner_verified_at ?? null,
                'listing_limit' => null,
                'amount' => null,
                'start_date' => null,
                'message' => 'No subscription found.',
            ];
        }

        // Defensive expiry in case scheduler has not run yet.
        if ($subscription->status === 'active' && $subscription->end_date && Carbon::parse($subscription->end_date)->startOfDay()->lte(now()->startOfDay())) {
            Log::info('Subscription expired on-demand during status fetch.', [
                'subscription_id' => $subscription->id,
                'end_date' => $subscription->end_date,
                'owner_id' => $owner->id ?? null,
                'user_id' => $userId,
            ]);
            $this->expireSubscriptionAndOwner((int) $subscription->id);
            $subscription = DB::table('subscriptions')->where('id', $subscription->id)->first();
        }

        $daysLeft = null;
        $isExpiringSoon = false;
        if ($subscription->end_date) {
            $daysLeft = now()->startOfDay()->diffInDays(Carbon::parse($subscription->end_date)->startOfDay(), false);
            $isExpiringSoon = $subscription->status === 'active' && $daysLeft >= 0 && $daysLeft <= $warningDays;
        }

        return [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'plan_name' => $subscription->plan_name,
            'billing_cycle' => $subscription->billing_cycle,
            'amount' => $subscription->amount,
            'start_date' => $subscription->start_date,
            'listing_limit' => $subscription->listing_limit,
            'end_date' => $subscription->end_date,
            'days_left' => $daysLeft,
            'is_expiring_soon' => $isExpiringSoon,
            'can_manage_properties' => $subscription->status === 'active',
            'warning_sent_at' => $subscription->warning_sent_at ?? null,
            'owner_verification_status' => $owner->owner_verification_status ?? 'unverified',
            'owner_verified_at' => $owner->owner_verified_at ?? null,
            'message' => $subscription->status === 'active'
                ? ($isExpiringSoon ? 'Subscription is about to expire.' : 'Subscription is active.')
                : 'Subscription is not active.',
        ];
    }

    public function expireSubscriptionAndOwner(int $subscriptionId): bool
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();
        if (!$subscription || $subscription->status === 'expired') {
            return false;
        }

        $resolvedOwnerId = $subscription->owner_id;
        if (!$resolvedOwnerId && $subscription->user_id) {
            $resolvedOwnerId = DB::table('owners')->where('user_id', $subscription->user_id)->value('id');
        }

        DB::transaction(function () use ($subscription, $resolvedOwnerId) {
            DB::table('subscriptions')
                ->where('id', $subscription->id)
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);

            if ($resolvedOwnerId) {
                DB::table('properties')
                    ->where('owner_id', $resolvedOwnerId)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'pending',
                        'is_available' => false,
                        'updated_at' => now(),
                    ]);
            }
        });

        Log::info('Subscription expired and owner properties deactivated', [
            'subscription_id' => $subscriptionId,
            'owner_id' => $resolvedOwnerId,
        ]);

        return true;
    }
}











