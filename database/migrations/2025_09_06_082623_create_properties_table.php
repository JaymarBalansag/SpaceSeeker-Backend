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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId("owner_id")->constrained("owners", "id")->cascadeOnDelete();

            // Basic Info (Common to all)
            $table->string("title");
            $table->string("thumbnail")->nullable();
            $table->text("description")->nullable();

            // =======================
            // PRICING & CONTRACT INFO
            // =======================

            // Common for both Rental & Lease
            $table->decimal("price", 10, 2); 
            $table->boolean("utilities_included"); // If bills (water/electricity) included

            $table->enum("agreement_type", ['rental','lease']); 
            // RENTAL = short/medium term (daily, weekly, monthly)
            // LEASE = long term (usually yearly, with formal contract)

            // For both (but usually RENTAL asks this upfront)
            $table->integer("advance_payment_months")->default(0)->nullable(); 
            // e.g., 2 months advance

            // For both (common in LEASE, sometimes RENTAL boarding houses)
            $table->decimal("deposit_required", 10, 2)->nullable(); 
            // e.g., security deposit

            // RENTAL = daily/weekly/monthly
            // LEASE = monthly/yearly (rarely weekly)
            $table->enum("payment_frequency", ['daily','weekly','monthly','yearly'])->default('monthly'); 
            $table->integer('lease_term_months')->nullable();
            $table->string('renewal_option')->nullable();
            $table->integer('notice_period')->nullable();
            $table->boolean('has_curfew')->default(false)->nullable();
            $table->time('curfew_time')->nullable();


            // =======================
            // PROPERTY CLASSIFICATION
            // =======================
            $table->foreignId("property_type_id")->constrained("property_types","id")->cascadeOnDelete();
            // Examples:
            // boarding_house, apartment, condo, house, commercial_space

            $table->enum("furnishing", ['unfurnished','semi-furnished','fully-furnished'])->nullable();
            // More relevant to condo/house/apt (less for boarding_house)

            $table->boolean("parking")->default(false)->nullable();
            // Relevant for apartment/condo/house/commercial

            $table->boolean("is_available")->default(false)->nullable();


            // =======================
            // PHYSICAL DETAILS
            // =======================
            // Common across property types
            $table->integer("bedrooms")->nullable();   // For house/apt/condo
            $table->integer("bathrooms")->nullable();  // All except maybe bare rooms
            $table->integer("bed_space")->nullable();  // Specifically for boarding houses
            $table->integer("single_bed")->nullable()->default(0);
            $table->integer("double_bed")->nullable()->default(0);
            $table->integer("public_bath")->nullable()->default(0);
            $table->integer("private_bath")->nullable()->default(0);

            $table->time("curfew_from")->nullable();
            $table->time("curfew_to")->nullable();
            $table->decimal("floor_area", 8, 2)->nullable(); // sqm, apt/condo/house/commercial
            $table->decimal("lot_area", 8, 2)->nullable();   // sqm, house/land/commercial

            $table->integer("max_size")->nullable(); // Max occupants (boarding_house, apt, condo)


            // =======================
            // LOCATION
            // =======================
            $table->decimal("latitude", 10, 8)->nullable();
            $table->decimal("longitude", 11, 8)->nullable();
            $table->string("region_name")->nullable();
            $table->string("state_name")->nullable();
            $table->string("town_name")->nullable();
            $table->string("village_name")->nullable();
            

            // =======================
            // RULES & EXTRA
            // =======================
            $table->text("rules")->nullable(); // e.g., "No pets, no smoking"
            $table->string('status')->default('pending');
            $table->integer('views')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
