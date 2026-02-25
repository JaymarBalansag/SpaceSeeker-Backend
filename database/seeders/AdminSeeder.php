<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->updateOrInsert(
            ['email' => 'balansagjm@gmail.com'], // prevent duplicates
            [
                'first_name' => 'Jaymar',
                'last_name'  => 'Balansag',
                'email'      => 'balansagjm@gmail.com',
                'password'   => Hash::make('adminadmin'),
                'role'       => 'admin',
                'email_verified_at' => Carbon::now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'luffy@gmail.com'], // prevent duplicates
            [
                'first_name' => 'Luffy',
                'last_name'  => 'Monkey',
                'email'      => 'luffy@gmail.com',
                'password'   => Hash::make('123123123'),
                'role'       => 'user',
                'email_verified_at' => Carbon::now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
