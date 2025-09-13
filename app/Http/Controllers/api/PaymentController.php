<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    //
    public function confirm(Request $request)
    {
        $user = Auth::user(); // from token

        // If already owner, block
        if ($user->role === 'owner') {
            return response()->json([
                'message' => 'You are already an Owner.',
                'user' => $user
            ], 400);
        }

        // Update user role
        DB::table('users')
            ->where('id', $user->id)
            ->update(['role' => 'owner', 'updated_at' => now()]);

        // Prepare plan dates
        $today = now();
        $due = null;
        $plan = strtolower($request->plan ?? 'per_transaction');

        if ($plan === 'monthly') {
            $due = $today->copy()->addMonth();
        } elseif ($plan === 'yearly') {
            $due = $today->copy()->addYear();
        } else {
            $due = $today->copy()->addDay();
        }

        // Insert into owners table
        DB::table('owners')->insert([
            'user_id' => $user->id,
            'plan' => $plan,
            'plan_start' => $today,
            'plan_due' => $due,
            'listing_limit' => $plan === 'yearly' ? 50 : ($plan === 'monthly' ? 20 : 5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Refresh user (with new role)
        $updatedUser = DB::table('users')->where('id', $user->id)->first();

        return response()->json([
            'message' => 'Payment confirmed. You are now an Owner!',
            'user' => $updatedUser
        ]);
    }
}
