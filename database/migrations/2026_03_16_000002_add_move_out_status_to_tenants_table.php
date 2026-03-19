<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('active','inactive','move_out') NOT NULL DEFAULT 'inactive'");
    }

    public function down(): void
    {
        DB::statement("UPDATE tenants SET status='inactive' WHERE status NOT IN ('active','inactive')");
        DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'inactive'");
    }
};
