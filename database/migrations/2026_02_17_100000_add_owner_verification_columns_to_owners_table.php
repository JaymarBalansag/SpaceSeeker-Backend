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
        Schema::table('owners', function (Blueprint $table) {
            $table->enum('owner_verification_status', ['unverified', 'pending', 'verified', 'rejected'])
                ->default('unverified')
                ->after('status');
            $table->timestamp('owner_verified_at')->nullable()->after('owner_verification_status');
            $table->text('owner_verification_rejected_reason')->nullable()->after('owner_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn([
                'owner_verification_rejected_reason',
                'owner_verified_at',
                'owner_verification_status',
            ]);
        });
    }
};
