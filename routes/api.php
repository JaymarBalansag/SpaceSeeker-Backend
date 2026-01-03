<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPreference;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TenantsController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\RecommendedController;
use App\Http\Controllers\Api\Admin\Users\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\Owner\OwnerController as AdminOwnerController;
use App\Http\Controllers\Api\Admin\Property\PropertyController as AdminPropertyController;

Route::controller(AuthController::class)->group(function(){
    Route::post("/login", "login");
    Route::post("/register", "register");
    Route::post("/refresh", "refresh");

});

Route::controller(PropertyController::class)->group(function() {
        Route::get("/amenities", "getAmenities");
        Route::get("/facilities", "getFacilities");
        Route::get("/property_types", "getPropertyTypes");
        Route::get("/properties", "ReadProperties");
        Route::get('/properties/filters', 'getFilteredProperty');
        Route::get("/properties/filters/type", 'getTypeFilter');
        Route::get('/properties/{id}', 'showProperty');
        Route::get("/properties/type/{type_id}{property_id}", "getPropertyByType");
        Route::get("/property/search/{query}/page/{page}", "searchProperty");


});

Route::controller(RecommendedController::class)->group(function() {
    Route::get("/default", "byDefault");
    Route::get("/recent", "recentProperties");
});



Route::middleware("auth:sanctum")->group(function(){

    Route::controller(RecommendedController::class)->group(function() {
        Route::get("/nearby", "byNearYou");
        Route::get("/prefferedAmenities", "byPreferredAmenities");
        Route::get("/prefferedTypes", "byPrefferedTypes");
        Route::get("/popularTypes", "byPopularTypes");
    });

    // Route::get('/user', function (Request $request) {
    //     return $request->user();
    // });

    Route::controller(UserController::class)->group(function(){
        Route::post("/profile_completion", "completeProfile");
        Route::get("/user", "getUser");
        Route::get("/UID", "getUserID");
    });


    Route::controller(AuthController::class)->group(function(){
        Route::post("/logout", "logout");
    });

    Route::controller(PropertyController::class)->group(function(){
        Route::post("/is-subscribing", "isSubscribing");


        // Property CRUD

        Route::post('/properties', 'createProperty');

        Route::get("/owner/properties", "readOwnerProperties");
        Route::put('/properties/{id}', 'updateProperty');
        Route::delete('/properties/{id}', 'deleteProperty');

        

    });

    Route::controller(PaymentController::class)->group(function() {
        Route::post("/payment/confirm", 'confirm');
    });

    Route::controller(LocationController::class)->group(function(){
        Route::get("/regions", "getRegions"); // Get all regions
        Route::get("/provinces/{region_code}", "getProvinces"); // Get provinces by region
        Route::get("/municities/{province_code}", "getMunCities"); // Get municipalities by province
        Route::get("/barangays/{muncity_code}", "getBarangays"); // Get barangays by municipality
    });

    Route::controller(BookingController::class)->group(function() {
        Route::post('/bookings/submit_booking', 'submitBookingRequest');
        Route::get("/bookings/pending", "getPendingUserBookings");

        Route::post('/bookings/{booking_id}/approve', 'approveBooking');
    });

    Route::controller(TenantsController::class)->group(function() {
        Route::get("/tenants/property/{propertyId}", "SelectTenantsByProperty");
        Route::get("/tenants", "getAllTenants");
    });

    Route::controller(MessageController::class)->group(function() {
        Route::get('/messages/{userId}', 'fetchMessages');
        Route::post('/messages/send', 'sendMessage');
        Route::post('/messages/start', 'startMessage');
        Route::get('/chats', 'chatList');
    });

    Route::controller(UserPreference::class)->group(function() {
        Route::get('/user/preferences', 'getUserPreferences');
        Route::post('/user/preferences', 'updateUserPreferences');
        Route::get('/user/preferences/edit', 'getUserPreferencesForEdit');
    });
    
});
// Test

Route::middleware(["auth:sanctum", "is_admin"])->group(function() {
    // Admin specific routes can be added here
    Route::controller(AdminPropertyController::class)->group(function() {
        Route::get("/admin/properties/active", "getActiveProperties");
        Route::get("/admin/properties/pending", "getPendingProperties");
        Route::get("/admin/properties/rejected", "getRejectedProperties");
        Route::get('/admin/properties/{id}', 'showPropertyDetails');
        
        // Actions
        Route::put('/admin/properties/{id}/approve', 'approveProperty');
    });

    Route::controller(AdminOwnerController::class)->group(function(){
        Route::get("/admin/owner/", "getAllOwner");
        Route::get("/admin/owner/inactive", "getInactiveOwner");
        Route::get("/admin/owner/active", "getActiveOwner");
    });

    Route::controller(AdminUserController::class)->group(function(){
        Route::get("/admin/users", "getAllUsers");
        Route::get("/admin/users/completed", "getCompleteProfile");
        Route::get("/admin/users/incomplete", "getIncompleteProfile");
    });
});

