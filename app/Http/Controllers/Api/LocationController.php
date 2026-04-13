<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class LocationController extends Controller
{
    public function getRegions()
    {
        $regions = DB::table('regions')->get();
        return response()->json($regions);
    }

    public function getProvinces($region_code)
    {
        $provinces = DB::table('provinces')->where('regCode', $region_code)->get();
        return response()->json($provinces);
    }

    public function getMunCities($province_code)
    {
        $muncities = DB::table('muncities')->where('provCode', $province_code)->get();
        return response()->json($muncities);
    }

    public function getBarangays($muncity_code)
    {
        $barangays = DB::table('barangays')->where('muncityCode', $muncity_code)->get();
        return response()->json($barangays);
    }
    
}
