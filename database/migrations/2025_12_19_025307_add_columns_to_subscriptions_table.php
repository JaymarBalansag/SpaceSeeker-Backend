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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Add user_id (nullable for now)
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->onDelete('cascade');

            // Make owner_id, start_date, end_date nullable for pending payments
            $table->foreignId('owner_id')->nullable()->change();
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();

            // Payment info
            $table->string('payment_provider')->nullable()->after('end_date');
            $table->string('payment_method')->nullable()->after('payment_provider');

            // Expand status enum to include 'failed'
            \Illuminate\Support\Facades\DB::statement("
                ALTER TABLE subscriptions 
                MODIFY status ENUM('pending','active','expired','cancelled','failed') DEFAULT 'pending'
            ");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'payment_provider', 'payment_method']);

            // Revert owner_id, start_date, end_date to NOT NULL (optional, careful with data)
            $table->foreignId('owner_id')->nullable(false)->change();
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();

            // Revert status enum
            \Illuminate\Support\Facades\DB::statement("
                ALTER TABLE subscriptions 
                MODIFY status ENUM('pending','active','expired','cancelled') DEFAULT 'pending'
            ");
        });
    
    }
};
