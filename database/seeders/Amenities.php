<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class Amenities extends Seeder
{
    /**
     * Run the database seeds.
     */ 
    public function run(): void
    {
        DB::table('amenities')->insert([
            ['amenity_name' => 'Bed'],
            ['amenity_name' => 'Table'],
            ['amenity_name' => 'Chair'],
            ['amenity_name' => 'Closet'],
            ['amenity_name' => 'Television'],
            ['amenity_name' => 'Refrigerator'],
            ['amenity_name' => 'Microwave'],
            ['amenity_name' => 'Electric Fan'],
            ['amenity_name' => 'Air Conditioner'],
            ['amenity_name' => 'Water Heater'],
            ['amenity_name' => 'Wi-fi'],
            ['amenity_name' => 'Air Condition'],

        ]);
    }
}
