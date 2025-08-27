<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\api\AuthController;
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
