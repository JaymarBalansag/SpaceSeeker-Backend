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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId("billing_id")->constrained("billings", "id");
            $table->decimal("amount_paid", 10, 2);
            $table->date("date_paid")->nullable();
            $table->string("proof")->nullable();
            $table->text("remarks")->nullable();
            $table->enum("status", ["pending", "verified", "rejected"])->default("pending");
            $table->string("payment_reference")->nullable();
            $table->string("payment_method")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
