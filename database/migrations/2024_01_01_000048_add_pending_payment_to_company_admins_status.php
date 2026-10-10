<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `company_admins` MODIFY `subscription_status` ENUM('trial','active','suspended','cancelled','pending_payment') DEFAULT 'trial'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `company_admins` MODIFY `subscription_status` ENUM('trial','active','suspended','cancelled') DEFAULT 'trial'");
    }
};
