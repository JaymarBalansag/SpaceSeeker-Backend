<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class EmailVerificationController extends Controller
{
    public function checkResendVerificationStatus(Request $request){

        $user = Auth::user();

        if(!$user){
            return response()->json([
                "message" => "User not found"
            ], 404);
        }

        if($user->email_verified_at){
            $isVerified = true;
        } else {
            $isVerified = false;
        }

        return response()->json([
            "is_verified" => $isVerified
        ]);
    }
}
