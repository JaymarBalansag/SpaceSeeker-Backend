<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MessageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('messages')->insert([
            [
                'sender_id' => 5,
                'receiver_id' => 1,
                'message' => 'Hello, how are you?',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sender_id' => 1,
                'receiver_id' => 5,
                'message' => 'Hi! what is up?',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
