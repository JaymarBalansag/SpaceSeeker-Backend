<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class PropertyTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('property_types')->insert([
            ['type_name' => 'Boarding House'],
            ['type_name' => 'Apartment'],
            ['type_name' => 'Condo'],
            ['type_name' => 'House'],
            ['type_name' => 'Commercial Space'],
        ]);
    }
}
