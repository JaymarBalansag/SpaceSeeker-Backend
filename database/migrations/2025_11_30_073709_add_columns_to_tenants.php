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
        Schema::table('tenants', function (Blueprint $table) {
            $table->integer('stay_duration')->nullable();
            $table->date('move_in_date')->nullable();
            $table->integer('occupants_num')->nullable();
            $table->integer('lease_duration')->nullable();
            $table->text('room_preference')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('agreement')->required()->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            //
        });
    }
};
