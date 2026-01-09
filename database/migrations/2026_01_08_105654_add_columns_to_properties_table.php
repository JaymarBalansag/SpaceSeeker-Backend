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
            $table->integer("single_bed")->nullable()->default(0);
            $table->integer("double_bed")->nullable()->default(0);
            $table->integer("public_bath")->nullable()->default(0);
            $table->integer("private_bath")->nullable()->default(0);

            $table->time("curfew_from")->nullable();
            $table->time("curfew_to")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn("single_bed");
            $table->dropColumn("double_bed");
            $table->dropColumn("public_bath");
            $table->dropColumn("private_bath");
            $table->dropColumn("curfew_from");
            $table->dropColumn("curfew_to");
        });
    }
};
