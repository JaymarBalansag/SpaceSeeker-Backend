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
        Schema::table('properties', function (Blueprint $table) {
            // Lease-related
            $table->integer('lease_term_months')->nullable()->after('advance_payment_months');
            $table->string('renewal_option')->nullable()->after('lease_term_months');
            $table->integer('notice_period')->nullable()->after('renewal_option');

            $table->boolean('has_curfew')->default(false)->after('notice_period');

            $table->time('curfew_time')->nullable()->after('has_curfew');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            //
        });
    }
};
