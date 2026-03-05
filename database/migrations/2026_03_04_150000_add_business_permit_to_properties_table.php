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
            $table->string('business_permit_path')
                ->nullable()
                ->after('thumbnail');
            $table->timestamp('business_permit_uploaded_at')
                ->nullable()
                ->after('business_permit_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'business_permit_uploaded_at',
                'business_permit_path',
            ]);
        });
    }
};
