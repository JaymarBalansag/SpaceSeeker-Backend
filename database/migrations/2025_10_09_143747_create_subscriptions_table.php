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
            $table->foreignId('owner_id')->constrained('owners')->onDelete('cascade');
            
            // Plan details
            $table->string('plan_name'); // e.g. "Monthly", "Annual", "Pro Plan"
            $table->decimal('amount', 10, 2);
            $table->enum('billing_cycle', ['monthly', 'annual']);
            
            // Dates
            $table->date('start_date');
            $table->date('end_date');
            
            // Payment + Status
            $table->string('payment_reference')->nullable(); // from PayMongo or manual
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])->default('pending');
            
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
