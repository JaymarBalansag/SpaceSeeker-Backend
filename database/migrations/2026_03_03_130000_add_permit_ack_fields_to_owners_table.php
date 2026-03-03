<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->boolean('permit_compliance_acknowledged')
                ->default(false)
                ->after('status');
            $table->timestamp('permit_compliance_acknowledged_at')
                ->nullable()
                ->after('permit_compliance_acknowledged');
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn([
                'permit_compliance_acknowledged_at',
                'permit_compliance_acknowledged',
            ]);
        });
    }
};

