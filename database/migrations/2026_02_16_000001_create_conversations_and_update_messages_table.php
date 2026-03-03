<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_low_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_high_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['user_low_id', 'user_high_id']);
            $table->index('last_message_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('id')->constrained('conversations')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable()->after('updated_at');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['sender_id', 'receiver_id', 'created_at']);
        });

        $pairs = DB::table('messages')
            ->selectRaw('LEAST(sender_id, receiver_id) as low_id, GREATEST(sender_id, receiver_id) as high_id')
            ->groupByRaw('LEAST(sender_id, receiver_id), GREATEST(sender_id, receiver_id)')
            ->get();

        foreach ($pairs as $pair) {
            DB::table('conversations')->insertOrIgnore([
                'user_low_id' => $pair->low_id,
                'user_high_id' => $pair->high_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::statement(
            "UPDATE messages m
             JOIN conversations c
               ON c.user_low_id = LEAST(m.sender_id, m.receiver_id)
              AND c.user_high_id = GREATEST(m.sender_id, m.receiver_id)
             SET m.conversation_id = c.id
             WHERE m.conversation_id IS NULL"
        );

        $conversationStats = DB::table('messages')
            ->selectRaw('conversation_id, MAX(created_at) as last_message_at, MAX(id) as last_message_id')
            ->whereNotNull('conversation_id')
            ->groupBy('conversation_id')
            ->get();

        foreach ($conversationStats as $stat) {
            DB::table('conversations')
                ->where('id', $stat->conversation_id)
                ->update([
                    'last_message_id' => $stat->last_message_id,
                    'last_message_at' => $stat->last_message_at,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'conversation_id')) {
            $fkExists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'messages')
                ->where('COLUMN_NAME', 'conversation_id')
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->exists();

            if ($fkExists) {
                // Drop FK first so MySQL allows removing dependent indexes.
                Schema::table('messages', function (Blueprint $table) {
                    $table->dropForeign(['conversation_id']);
                });
            }

            $conversationCreatedAtIndexExists = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'messages')
                ->where('INDEX_NAME', 'messages_conversation_id_created_at_index')
                ->exists();

            if ($conversationCreatedAtIndexExists) {
                Schema::table('messages', function (Blueprint $table) {
                    $table->dropIndex('messages_conversation_id_created_at_index');
                });
            }

            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('conversation_id');
            });
        }

        if (Schema::hasColumn('messages', 'read_at')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('read_at');
            });
        }

        Schema::dropIfExists('conversations');
    }
};
