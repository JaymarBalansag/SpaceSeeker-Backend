<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        DB::table('users')->updateOrInsert(
            ['email' => 'owner@example.com'],
            [
                'first_name' => 'Sample',
                'last_name' => 'Owner',
                'email' => 'owner@example.com',
                'password' => Hash::make('123123123'),
                'role' => 'owner',
                'email_verified_at' => Carbon::now(),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $ownerUser = DB::table('users')->where('email', 'owner@example.com')->first();

        if ($ownerUser) {
            $owner = DB::table('owners')->where('user_id', $ownerUser->id)->first();

            $ownerData = [
                'paymentType' => 'gcash',
                'phone_number' => '09171234567',
                'status' => 'active',
                'updated_at' => $now,
            ];

            if ($owner) {
                DB::table('owners')->where('id', $owner->id)->update($ownerData);
                $ownerId = $owner->id;
            } else {
                $ownerId = DB::table('owners')->insertGetId(array_merge($ownerData, [
                    'user_id' => $ownerUser->id,
                    'created_at' => $now,
                ]));
            }

            $activeSubscription = DB::table('subscriptions')
                ->where('owner_id', $ownerId)
                ->where('status', 'active')
                ->first();

            $subscriptionData = [
                'user_id' => $ownerUser->id,
                'owner_id' => $ownerId,
                'plan_name' => 'Monthly',
                'amount' => 1,
                'billing_cycle' => 'monthly',
                'start_date' => $now->toDateString(),
                'end_date' => $now->copy()->addMonth()->toDateString(),
                'payment_provider' => 'paymongo',
                'payment_method' => 'qrph',
                'payment_reference' => 'seed-owner-subscription',
                'status' => 'active',
                'listing_limit' => 2,
                'updated_at' => $now,
            ];

            if ($activeSubscription) {
                DB::table('subscriptions')
                    ->where('id', $activeSubscription->id)
                    ->update($subscriptionData);
            } else {
                DB::table('subscriptions')->insert(array_merge($subscriptionData, [
                    'created_at' => $now,
                ]));
            }
        }
    }
}
