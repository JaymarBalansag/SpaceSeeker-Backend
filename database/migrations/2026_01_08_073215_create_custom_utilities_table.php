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
        Schema::create('custom_utilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId("property_id")->nullable()->constrained("properties", "id")->cascadeOnDelete();
            $table->string("custom_utility")->nullable();
            $table->timestamps();
        });
        
        Schema::create('custom_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId("property_id")->nullable()->constrained("properties", "id")->cascadeOnDelete();
            $table->string("custom_amenity")->nullable();
            $table->timestamps();
        });

        Schema::create('custom_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId("property_id")->nullable()->constrained("properties", "id")->cascadeOnDelete();
            $table->string("custom_facility")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_utilities');
        Schema::dropIfExists('custom_amenities');
        Schema::dropIfExists('custom_facilities');
    }
};
