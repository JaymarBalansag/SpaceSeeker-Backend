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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId("user_id")->constrained("users", "id")->cascadeOnDelete();
            $table->foreignId("property_id")->constrained("properties", "id")->cascadeOnDelete();
            $table->integer('stay_duration')->nullable();
            $table->date("move_in_date")->nullable();
            $table->integer("occupants_num")->nullable();
            $table->integer("lease_duration")->nullable();
            $table->text("room_preference")->nullable();
            $table->text("notes")->nullable();
            $table->boolean("agreement")->default(false);
            $table->enum("status", ["pending", "approved", "rejected"])->default("pending");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
