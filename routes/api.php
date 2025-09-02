<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\api\AuthController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PropertyController;

Route::controller(AuthController::class)->group(function(){
    Route::post("/login", "login");
    Route::post("/register", "register");
    Route::post("/refresh", "refresh");

});

Route::middleware("auth:sanctum")->group(function(){
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::controller(AuthController::class)->group(function(){
        Route::post("/logout", "logout");
    });
    Route::controller(PropertyController::class)->group(function(){
        Route::post("/is-subscribing", "isSubscribing");
    });
});

Route::controller(LocationController::class)->group(function(){
    Route::get("/regions", "getRegions"); // Get all regions
    Route::get("/provinces/{region_code}", "getProvinces"); // Get provinces by region
    Route::get("/municities/{province_code}", "getMunCities"); // Get municipalities by province
    Route::get("/barangays/{muncity_code}", "getBarangays"); // Get barangays by municipality
});