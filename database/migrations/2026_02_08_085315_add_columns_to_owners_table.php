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
        Schema::table('owners', function (Blueprint $table) {
            $table->enum("paymentType", ["gcash","paymaya"])->nullable();
            $table->string("phone_number")->nullable();
            $table->string("business_permit")->nullable();
            $table->string("valid_govt_id")->nullable();
            $table->enum("status", ["pending","active","failed"])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            //
        });
    }
};
