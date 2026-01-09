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
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            // The link for active tenants
            $table->foreignId("tenant_id")->nullable()->constrained("tenants")->onDelete('set null');
            $table->foreignId("property_id")->constrained("properties", "id")->cascadeOnDelete();
            // The archival/snapshot columns
            $table->string("tenant_name_snapshot")->nullable(); 
            $table->string("tenant_email_snapshot")->nullable();

            $table->decimal("rent_amount", 10, 2);
            $table->string("rent_cycle");
            $table->date("rent_start");
            $table->date("rent_due");
            $table->enum("rent_status", ["pending","paid","unpaid","overdue"])->default("pending");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};
