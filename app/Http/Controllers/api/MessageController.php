<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function fetchMessages($userId){
        $authId = Auth::id();

        $messages = DB::table('messages')
            ->join('users as sender', 'messages.sender_id', '=', 'sender.id')
            ->join('users as receiver', 'messages.receiver_id', '=', 'receiver.id')
            ->where(function($q) use ($authId, $userId) {
                $q->where('messages.sender_id', $authId)
                ->where('messages.receiver_id', $userId);
            })
            ->orWhere(function($q) use ($authId, $userId) {
                $q->where('messages.sender_id', $userId)
                ->where('messages.receiver_id', $authId);
            })
            ->orderBy('messages.created_at', 'asc')
            ->select(
                'messages.id',
                'messages.sender_id',
                'messages.receiver_id',
                'messages.message',
                'messages.created_at',
                'sender.first_name as sender_first_name',
                'sender.last_name as sender_last_name',
                'receiver.first_name as receiver_first_name',
                'receiver.last_name as receiver_last_name'
            )
            ->get();

        return response()->json([
            'messages' => $messages
        ], 200);
    }

    public function chatList() {
        $authId = Auth::id();

        $chats = DB::table('messages')
            ->selectRaw("CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END as user_id", [$authId])
            ->selectRaw("MAX(created_at) as last_message_time")
            ->selectRaw("SUBSTRING_INDEX(GROUP_CONCAT(message ORDER BY created_at DESC), ',', 1) as lastMessage")
            ->where('sender_id', $authId)
            ->orWhere('receiver_id', $authId)
            ->groupBy('user_id')
            ->get();

        // Optionally, fetch user info for each chat
        $chatWithUserInfo = $chats->map(function($chat) {
            $user = DB::table('users')->where('id', $chat->user_id)->first();
            return [
                'user_id' => $chat->user_id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'lastMessage' => $chat->lastMessage,
                'last_message_time' => $chat->last_message_time,
                'profile_photo' => $user->user_img ? asset('storage/' . $user->user_img) : null,

            ];
        });

        return response()->json([
            'chats' => $chatWithUserInfo
        ],200);
    }


    public function sendMessage(Request $request){
        try {
            $request->validate([
                'receiver_id' => 'required|exists:users,id',
                'message' => 'required|string',
            ]);

            $id = DB::table('messages')->insertGetId([
                'sender_id'   => Auth::id(),
                'receiver_id' => $request->receiver_id,
                'message'     => $request->message,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Re-hydrate as Eloquent model (IMPORTANT)
            $message = Message::findOrFail($id);

            // Broadcast to receiver only
            broadcast(new MessageSent($message))->toOthers();

            return response()->json(['message' => $message], 201);

            
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Failed to send message',
                'details' => $th->getMessage()
            ], 500);        
        }   
        
    }

    public function startMessage(Request $request){
        try {

            $request->validate([
                'receiver_id' => 'required|exists:users,id',
                'property_id' => 'required|integer',
            ]);

            // Check if a conversation already exists
            $existingMessage = DB::table('messages')
                ->where(function($q) use ($request) {
                    $q->where('sender_id', Auth::id())
                      ->where('receiver_id', $request->receiver_id);
                })
                ->orWhere(function($q) use ($request) {
                    $q->where('sender_id', $request->receiver_id)
                      ->where('receiver_id', Auth::id());
                })
                ->first();

            if ($existingMessage) {
                return response()->json([
                    'message' => 'Conversation already exists',
                    'messages' => $existingMessage->receiver_id
                ], 200);
            }

            // Gets the info of the user who start a chat
            $userId = Auth::id();
            $AuthUserInfo = DB::table("users")
            ->select("first_name", "last_name")
            ->where("id", $userId)
            ->first();
            $username = $AuthUserInfo->first_name . ' ' . $AuthUserInfo->last_name;

            // Get property title
            $property = DB::table('properties')
                ->select('title')
                ->where('id', $request->property_id)
                ->first();
            $propertyTitle = $property ? $property->title : 'Your property';


            // If no existing conversation, create a placeholder message
            $id = DB::table('messages')->insertGetId([
                'sender_id'   => Auth::id(),
                'receiver_id' => $request->receiver_id,
                'message'     => 'Hi, I\'m ' . $username . ' interested in your property ' . $propertyTitle, // Placeholder
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $createdMessage = DB::table('messages')->where('id', $id)->first();

            return response()->json([
                'message' => 'Conversation started',
                'receiver_id' => $createdMessage->receiver_id
            ], 200);


        } catch (\Throwable $th) {
            //throw $th;
            Log::error('Start Message Error: ' . $th->getMessage());
            return response()->json([
                'error' => 'Failed to start message',
                'details' => $th->getMessage()
            ], 500);
        }
    }

}
