<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function sendMessage(Request $request){
        $data = [
            'sender_id' => Auth::id(),
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // insert message
        DB::table('messages')->insert($data);

        // you can broadcast $data directly
        broadcast(new MessageSent((object)$data))->toOthers();

        return response()->json($data);
    }
}
