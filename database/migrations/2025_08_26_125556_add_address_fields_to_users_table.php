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
        Schema::table('users', function (Blueprint $table) {
            $table->string('street')->nullable()->after('longitude');
            $table->string('barangay')->nullable()->after('street');
            $table->string('city_municipality')->nullable()->after('barangay');
            $table->string('province')->nullable()->after('city_municipality');
            $table->string('region')->nullable()->after('province');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
