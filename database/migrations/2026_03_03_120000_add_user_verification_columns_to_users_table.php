<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('user_verification_status', ['unverified', 'pending', 'verified', 'rejected'])
                ->default('unverified')
                ->after('isComplete');
            $table->string('user_valid_govt_id_path')->nullable()->after('user_verification_status');
            $table->timestamp('user_verification_submitted_at')->nullable()->after('user_valid_govt_id_path');
            $table->timestamp('user_verified_at')->nullable()->after('user_verification_submitted_at');
            $table->text('user_verification_rejected_reason')->nullable()->after('user_verified_at');
            $table->foreignId('user_verified_by_admin_id')
                ->nullable()
                ->after('user_verification_rejected_reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_verified_by_admin_id');
            $table->dropColumn([
                'user_verification_rejected_reason',
                'user_verified_at',
                'user_verification_submitted_at',
                'user_valid_govt_id_path',
                'user_verification_status',
            ]);
        });
    }
};

