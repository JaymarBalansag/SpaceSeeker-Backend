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
        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('deposit_paid_amount', 10, 2)->default(0)->after('rent_status');
            $table->decimal('advance_paid_amount', 10, 2)->default(0)->after('deposit_paid_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn('deposit_paid_amount');
            $table->dropColumn('advance_paid_amount');
        });
    }
};
