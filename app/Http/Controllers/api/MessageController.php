<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Observers\Notifications\Logic\NotificationLogicObserver;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected NotificationLogicObserver $notificationLogicObserver
    ) {
    }

    public function fetchMessages(Request $request, $userId)
    {
        $authId = Auth::id();
        [$low, $high] = $this->pair($authId, (int) $userId);

        $conversation = DB::table('conversations')
            ->where('user_low_id', $low)
            ->where('user_high_id', $high)
            ->first();

        if (! $conversation) {
            return response()->json([
                'messages' => [],
                'next_cursor' => null,
                'has_more' => false,
                'conversation_id' => null,
            ], 200);
        }

        return $this->messagesByConversation($request, $conversation->id);
    }

    public function chatList(Request $request)
    {
        return $this->listConversations($request);
    }

    public function listConversations(Request $request)
    {
        $authId = Auth::id();
        $limit = max(1, min((int) $request->integer('limit', 20), 50));
        $cursor = $request->query('cursor');

        $unreadMessages = DB::table('messages')
            ->select('conversation_id', DB::raw('COUNT(*) as unread_count'))
            ->where('receiver_id', $authId)
            ->whereNull('read_at')
            ->groupBy('conversation_id');

        $query = DB::table('conversations as c')
            ->leftJoin('messages as lm', 'lm.id', '=', 'c.last_message_id')
            ->leftJoinSub($unreadMessages, 'um', function ($join) {
                $join->on('um.conversation_id', '=', 'c.id');
            })
            ->where(function ($q) use ($authId) {
                $q->where('c.user_low_id', $authId)
                    ->orWhere('c.user_high_id', $authId);
            })
            ->select(
                'c.id',
                'c.user_low_id',
                'c.user_high_id',
                'c.property_id',
                'c.last_message_id',
                'c.last_message_at',
                'lm.message as last_message_text',
                DB::raw('COALESCE(um.unread_count, 0) as unread_count')
            )
            ->orderByDesc('c.last_message_at')
            ->orderByDesc('c.id');

        if ($cursor) {
            [$cursorTime, $cursorId] = $this->decodeCursor($cursor);
            $query->where(function ($q) use ($cursorTime, $cursorId) {
                $q->where('c.last_message_at', '<', $cursorTime)
                    ->orWhere(function ($q2) use ($cursorTime, $cursorId) {
                        $q2->where('c.last_message_at', '=', $cursorTime)
                            ->where('c.id', '<', $cursorId);
                    });
            });
        }

        $conversations = $query->limit($limit + 1)->get();
        $hasMore = $conversations->count() > $limit;
        $page = $conversations->take($limit)->values();

        $otherUserIds = $page
            ->map(fn ($c) => (int) ($c->user_low_id == $authId ? $c->user_high_id : $c->user_low_id))
            ->unique()
            ->values();

        $users = DB::table('users')
            ->whereIn('id', $otherUserIds)
            ->select('id', 'first_name', 'last_name', 'user_img')
            ->get()
            ->keyBy('id');

        $chats = $page->map(function ($conversation) use ($authId, $users) {
            $otherUserId = (int) ($conversation->user_low_id == $authId ? $conversation->user_high_id : $conversation->user_low_id);
            $user = $users->get($otherUserId);

            $unreadCount = (int) ($conversation->unread_count ?? 0);

            return [
                'conversation_id' => $conversation->id,
                'user_id' => $otherUserId,
                'name' => $user ? trim($user->first_name . ' ' . $user->last_name) : null,
                'lastMessage' => $conversation->last_message_text,
                'last_message_time' => $conversation->last_message_at,
                'profile_photo' => ($user && $user->user_img) ? asset('storage/' . $user->user_img) : null,
                'unread_count' => $unreadCount,
                'has_unread' => $unreadCount > 0,
            ];
        });

        $nextCursor = null;
        if ($hasMore && $page->isNotEmpty() && $page->last()->last_message_at) {
            $last = $page->last();
            $nextCursor = $this->encodeCursor($last->last_message_at, $last->id);
        }

        return response()->json([
            'chats' => $chats,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ], 200);
    }

    public function startMessage(Request $request)
    {
        return $this->startConversation($request);
    }

    public function startConversation(Request $request)
    {
        try {
            $request->validate([
                'receiver_id' => 'required|exists:users,id',
                'property_id' => 'nullable|exists:properties,id',
            ]);

            $authId = Auth::id();
            $receiverId = (int) $request->receiver_id;
            if ($receiverId === $authId) {
                return response()->json(['error' => 'Cannot start a conversation with yourself'], 422);
            }

            [$low, $high] = $this->pair($authId, $receiverId);

            $conversation = DB::table('conversations')
                ->where('user_low_id', $low)
                ->where('user_high_id', $high)
                ->first();

            $created = false;
            if (! $conversation) {
                try {
                    $id = DB::table('conversations')->insertGetId([
                        'user_low_id' => $low,
                        'user_high_id' => $high,
                        'property_id' => $request->input('property_id'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $conversation = DB::table('conversations')->where('id', $id)->first();
                    $created = true;
                } catch (\Throwable $th) {
                    // Handles unique-race safely.
                    $conversation = DB::table('conversations')
                        ->where('user_low_id', $low)
                        ->where('user_high_id', $high)
                        ->first();
                }
            }

            if ($conversation && ! $conversation->property_id && $request->filled('property_id')) {
                DB::table('conversations')
                    ->where('id', $conversation->id)
                    ->update([
                        'property_id' => $request->input('property_id'),
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'message' => 'Conversation ready',
                'conversation_id' => $conversation->id,
                'receiver_id' => $receiverId,
                'created' => $created,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Start Conversation Error: ' . $th->getMessage());
            return response()->json([
                'error' => 'Failed to start conversation',
                'details' => $th->getMessage(),
            ], 500);
        }
    }

    public function sendMessage(Request $request)
    {
        try {
            $request->validate([
                'conversation_id' => 'nullable|exists:conversations,id',
                'receiver_id' => 'nullable|exists:users,id',
                'message' => 'required|string',
            ]);

            if (! $request->filled('conversation_id') && ! $request->filled('receiver_id')) {
                return response()->json([
                    'error' => 'Either conversation_id or receiver_id is required',
                ], 422);
            }

            $authId = Auth::id();
            $conversation = null;
            $receiverId = null;

            if ($request->filled('conversation_id')) {
                $conversation = DB::table('conversations')
                    ->where('id', $request->conversation_id)
                    ->first();

                if (! $conversation) {
                    return response()->json(['error' => 'Conversation not found'], 404);
                }
                if (! $this->isParticipant($conversation, $authId)) {
                    return response()->json(['error' => 'Forbidden'], 403);
                }

                $receiverId = ($conversation->user_low_id == $authId)
                    ? $conversation->user_high_id
                    : $conversation->user_low_id;
            } else {
                $receiverId = (int) $request->receiver_id;
                if ($receiverId === $authId) {
                    return response()->json(['error' => 'Cannot message yourself'], 422);
                }

                [$low, $high] = $this->pair($authId, $receiverId);
                $conversation = DB::table('conversations')
                    ->where('user_low_id', $low)
                    ->where('user_high_id', $high)
                    ->first();

                if (! $conversation) {
                    try {
                        $conversationId = DB::table('conversations')->insertGetId([
                            'user_low_id' => $low,
                            'user_high_id' => $high,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
                    } catch (\Throwable $th) {
                        $conversation = DB::table('conversations')
                            ->where('user_low_id', $low)
                            ->where('user_high_id', $high)
                            ->first();
                    }
                }
            }

            $messageId = DB::table('messages')->insertGetId([
                'conversation_id' => $conversation->id,
                'sender_id' => $authId,
                'receiver_id' => $receiverId,
                'message' => $request->message,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('conversations')
                ->where('id', $conversation->id)
                ->update([
                    'last_message_id' => $messageId,
                    'last_message_at' => now(),
                    'updated_at' => now(),
                ]);

            $message = Message::findOrFail($messageId);
            broadcast(new MessageSent($message))->toOthers();

            return response()->json([
                'message' => $message,
                'conversation_id' => $conversation->id,
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Send Message Error: ' . $th->getMessage());
            return response()->json([
                'error' => 'Failed to send message',
                'details' => $th->getMessage(),
            ], 500);
        }
    }

    public function sendConversationMessage(Request $request, $conversationId)
    {
        $request->merge(['conversation_id' => (int) $conversationId]);
        return $this->sendMessage($request);
    }

    public function markConversationAsRead(Request $request, $conversationId)
    {
        $authId = Auth::id();
        $conversation = DB::table('conversations')->where('id', $conversationId)->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        if (! $this->isParticipant($conversation, $authId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->where('receiver_id', $authId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Conversation marked as read.',
            'conversation_id' => (int) $conversation->id,
        ], 200);
    }

    public function messagesByConversation(Request $request, $conversationId)
    {
        $authId = Auth::id();
        // Hard-cap conversation message paging to 10 items per request.
        $limit = max(1, min((int) $request->integer('limit', 10), 10));

        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        if (! $this->isParticipant($conversation, $authId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->select('id', 'conversation_id', 'sender_id', 'receiver_id', 'message', 'created_at', 'updated_at', 'read_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $cursor = $request->query('cursor');
        if ($cursor) {
            [$cursorTime, $cursorId] = $this->decodeCursor($cursor);
            $query->where(function ($q) use ($cursorTime, $cursorId) {
                $q->where('created_at', '<', $cursorTime)
                    ->orWhere(function ($q2) use ($cursorTime, $cursorId) {
                        $q2->where('created_at', '=', $cursorTime)
                            ->where('id', '<', $cursorId);
                    });
            });
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();

        $nextCursor = null;
        if ($hasMore && $page->isNotEmpty()) {
            $last = $page->last();
            $nextCursor = $this->encodeCursor($last->created_at, $last->id);
        }

        DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->where('receiver_id', $authId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'messages' => $page->reverse()->values(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'conversation_id' => $conversation->id,
        ], 200);
    }

    private function pair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private function isParticipant(object $conversation, int $userId): bool
    {
        return (int) $conversation->user_low_id === $userId || (int) $conversation->user_high_id === $userId;
    }

    private function encodeCursor(string $timestamp, int $id): string
    {
        return $timestamp . '|' . $id;
    }

    private function decodeCursor(string $cursor): array
    {
        $parts = explode('|', $cursor);
        if (count($parts) !== 2 || ! is_numeric($parts[1])) {
            abort(422, 'Invalid cursor');
        }

        return [$parts[0], (int) $parts[1]];
    }
}
