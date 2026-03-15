<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_limit_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->onDelete('cascade');
            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
            $table->integer('qty');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->enum('billing_cycle', ['monthly', 'annual']);
            $table->string('payment_provider')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->enum('status', ['pending', 'active', 'failed', 'expired'])->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_limit_addons');
    }
};
