<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class PropertyFacilities extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         DB::table('facilities')->insert([
            ['facility_name' => 'Parking'],
            ['facility_name' => 'Kitchen'],
            ['facility_name' => 'Laundry Area'],
            ['facility_name' => 'Gym'],
            ['facility_name' => 'Swimming Pool'],
        ]);
    }
}
