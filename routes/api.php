<?php

use App\Http\Controllers\Api\PayMongoController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPreference;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TenantsController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\RecommendedController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\OwnerReportController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\Api\Admin\Users\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\Owner\OwnerController as AdminOwnerController;
use App\Http\Controllers\Api\Admin\Property\PropertyController as AdminPropertyController;
use App\Http\Controllers\Api\EmailVerificationController;

Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {

    if (! URL::hasValidSignature($request)) {
        abort(403, 'Invalid or expired verification link.');
    }

    $user = User::findOrFail($id);

    if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
        abort(403, 'Invalid verification hash.');
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }

    return redirect(config('app.frontend_url') . '/Rentahub');

})->name('verification.verify');


Route::post('/email/verification-notification', function (Request $request) {

    if ($request->user()->hasVerifiedEmail()) {
        return response()->json([
            'message' => 'Email already verified'
        ], 400);
    }

    $request->user()->sendEmailVerificationNotification();

    return response()->json(['message' => 'Verification email resent']);
})
->middleware(['auth:sanctum', 'throttle:6,1']);
//  End of Email Verification Route

Route::controller(PayMongoController::class)->group(function () {
    Route::post('/paymongo/webhook', 'handleWebhook');
});

Route::middleware("is_public")->group(function () {

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
        Route::get('/properties/{id}/reviews', 'getPropertyReviews');
        Route::get("/properties/type/{type_id}/{property_id}", "getPropertyByType");
        // Backward compatibility for existing clients with missing slash.
        Route::get("/properties/type/{type_id}{property_id}", "getPropertyByType");
        Route::get("/property/search/{query}/page/{page}", "searchProperty");
        Route::post("/record-view/{id}", "recordView");
        Route::get("/categories/counts", "getCategoryCounts");
    });
});

Route::middleware("auth:sanctum")->group(function() {
    Route::controller(UserController::class)->group(function(){
        Route::get("/user", "getUser");
    });
    Route::controller(AuthController::class)->group(function(){
        Route::post("/logout", "logout");
    });

    Route::controller(PayMongoController::class)->group(function () {
        Route::post('/paymongo/create-payment', 'createPayment');
    });

    Route::controller(EmailVerificationController::class)->group(function() {
        Route::get('/check-resend-verification-status', "checkResendVerificationStatus");
    });

});

Route::middleware(["auth:sanctum", "verified"])->group(function(){

    Route::controller(SubscriptionController::class)->group(function() {
        Route::get("/subscription-status/{subscriptionId}", "getSubscriptionStatus");
        Route::get("/owner/subscription-status", "getOwnerSubscriptionStatus");
    });

    Route::get("/me", function(Request $request){
        return response()->json($request->user());
    });

    Route::controller(RecommendedController::class)->group(function() {
        Route::get("/default", "byDefault");
        Route::get("/recent", "recentProperties");
        Route::get("/nearby", "byNearYou");
        Route::get("/prefferedAmenities", "byPreferredAmenities");
        Route::get("/prefferedTypes", "byPrefferedTypes");
        Route::get("/trending", "byTrending");
        Route::get("/popularTypes", "byPopularTypes");
    });

    Route::controller(UserController::class)->group(function(){
        Route::post("/profile_completion", "completeProfile");
        Route::post("/update_profile", "updateProfile");
        Route::post("/update-location", "updateLocation");
        Route::get("/UID", "getUserID");
        Route::post("/verify-password", "verifyPassword");
        Route::post("/change-password", "changePassword");
    });

    Route::controller(PaymentController::class)->group(function() {
        Route::post("/payment/confirm", 'confirm');
        Route::post("/subscribe", "subscribe");
    });

    Route::controller(BookingController::class)->group(function() {
        Route::post('/bookings/submit_booking', 'submitBookingRequest');
    });

    Route::controller(PropertyController::class)->group(function() {
        Route::post('/properties/{id}/reviews', 'submitPropertyReview');
    });

    Route::controller(MessageController::class)->group(function() {
        Route::get('/messages/{userId}', 'fetchMessages');
        Route::post('/messages/send', 'sendMessage');
        Route::post('/messages/start', 'startMessage');
        Route::get('/chats', 'chatList');
        Route::get('/conversations', 'listConversations');
        Route::post('/conversations/start', 'startConversation');
        Route::get('/conversations/{conversationId}/messages', 'messagesByConversation');
        Route::post('/conversations/{conversationId}/messages', 'sendConversationMessage');
    });

    Route::controller(NotificationController::class)->group(function() {
        Route::get('/notifications', 'index');
        Route::post('/notifications/{id}/read', 'markAsRead');
        Route::post('/notifications/read-all', 'markAllAsRead');
    });

    Route::controller(UserPreference::class)->group(function() {
        Route::get('/user/preferences', 'getUserPreferences');
        Route::post('/user/preferences', 'updateUserPreferences');
        Route::get('/user/preferences/edit', 'getUserPreferencesForEdit');
    });
    
});

Route::middleware(["auth:sanctum", "is_tenant", "verified"])->group(function () {

    Route::controller(TenantsController::class)->group(function() {
        Route::get("/my-billings", "getMyBillings");
        Route::post("/submit-payment-records", "submitPayment");
    });

});

Route::middleware(["auth:sanctum", "is_owner", "verified"])->group(function() {

    Route::controller(PropertyController::class)->group(function() {
        Route::post('/properties', 'createProperty');
        Route::get("/owner/properties", "readOwnerProperties");
        Route::post("/properties/update/id/{id}", "updateProperty");
        Route::get("/properties/edit/id/{id}", "editProperty");
        Route::patch("/owner/properties/{id}/availability", "updateAvailability");
        Route::delete("/owner/properties/{id}", "deleteOwnerProperty");
    });

    Route::controller(BillingController::class)->group(function() {
        Route::get("/billings", "getBillings");
        Route::get("/owner/payments", "getPayments");
        Route::get("/owner/dashboard-summary", "getOwnerDashboardSummary");
        Route::post("/owner/payments/{paymentId}/verify", "verifyPayment");
        Route::post("/owner/payments/{paymentId}/reject", "rejectPayment");
    });

    Route::controller(SubscriptionController::class)->group(function() {
        Route::get("/listing-limit", "getPropertyLimit");
    });

    Route::controller(BookingController::class)->group(function(){
        Route::get("/bookings/pending", "getPendingUserBookings");
        Route::post('/bookings/{booking_id}/approve', 'approveBooking');
    });

    Route::controller(TenantsController::class)->group(function() {
        Route::get("/tenants/property/{propertyId}", "SelectTenantsByProperty");
        Route::get("/tenants", "getAllTenants");
        Route::post('/tenants/{id}/move-in', "moveInTenant");
    });

    Route::controller(OwnerReportController::class)->group(function() {
        Route::get('/owner/reports/tenant-summary', 'tenantSummary');
        Route::get('/owner/reports/booking-logs', 'bookingLogs');
        Route::get('/owner/reports/payment-analytics', 'paymentAnalytics');
    });
});

Route::middleware(["auth:sanctum", "is_admin", "verified"])->group(function() {
    // Admin specific routes can be added here
    Route::controller(AdminDashboardController::class)->group(function() {
        Route::get('/admin/overview', 'overview');
    });

    Route::controller(AdminPropertyController::class)->group(function() {
        Route::get("/admin/properties/active", "getActiveProperties");
        Route::get("/admin/properties/pending", "getPendingProperties");
        Route::get("/admin/properties/rejected", "getRejectedProperties");
        Route::get('/admin/properties/{id}', 'showPropertyDetails');
        
        // Actions
        Route::put('/admin/properties/{id}/approve', 'approveProperty');
    });

    Route::controller(AdminOwnerController::class)->group(function(){
        Route::get("/admin/owner", "getAllOwner");
        Route::get("/admin/owner/{ownerId}", "getOwnerDetails");
        Route::patch("/admin/owner/{ownerId}/verification", "updateOwnerVerification");
        Route::get("/admin/owner/inactive", "getInactiveOwner");
        Route::get("/admin/owner/active", "getActiveOwner");
    });

    Route::controller(AdminUserController::class)->group(function(){
        Route::get("/admin/users", "getAllUsers");
        Route::get("/admin/users/completed", "getCompleteProfile");
        Route::get("/admin/users/incomplete", "getIncompleteProfile");
    });
});
