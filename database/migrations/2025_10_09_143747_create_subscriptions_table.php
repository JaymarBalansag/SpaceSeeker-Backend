<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('owner_id')->constrained('owners')->onDelete('cascade')->nullable();
            
            // Plan details
            $table->string('plan_name'); // e.g. "Monthly", "Annual", "Pro Plan"
            $table->decimal('amount', 10, 2);
            $table->enum('billing_cycle', ['monthly', 'annual']);
            
            // Dates
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('payment_provider')->nullable();
            $table->string('payment_method')->nullable();
            
            // Payment + Status
            $table->string('payment_reference')->nullable(); // from PayMongo or manual
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending, failed'])->default('pending');
            
            // Other optional fields
            $table->integer('listing_limit')->default(5); // how many properties allowed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
