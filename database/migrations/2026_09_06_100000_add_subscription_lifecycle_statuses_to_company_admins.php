<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Widens company_admins.subscription_status to the full lifecycle the
// Trial/Suspension/Subscription policy needs — 'trial_expired' (transitional
// state right after trial_ends_at passes, before grace period/suspension is
// applied) and 'grace_period' (still using the CRM, counting down to
// suspension) — alongside the 5 values already in production use (trial,
// active, suspended, cancelled, pending_payment). Existing rows/behavior are
// untouched; this only adds two new enum members.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_admins')) {
            return;
        }

        DB::statement("ALTER TABLE `company_admins` MODIFY `subscription_status` ENUM('trial','trial_expired','active','pending_payment','grace_period','suspended','cancelled') DEFAULT 'trial'");

        Schema::table('company_admins', function ($table) {
            if (!Schema::hasColumn('company_admins', 'grace_period_ends_at')) {
                $table->timestamp('grace_period_ends_at')->nullable()->after('subscription_ends_at');
            }
            if (!Schema::hasColumn('company_admins', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('grace_period_ends_at');
            }
            if (!Schema::hasColumn('company_admins', 'suspended_reason')) {
                $table->string('suspended_reason', 255)->nullable()->after('suspended_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_admins')) {
            return;
        }

        Schema::table('company_admins', function ($table) {
            $table->dropColumn(['grace_period_ends_at', 'suspended_at', 'suspended_reason']);
        });

        DB::statement("UPDATE `company_admins` SET `subscription_status` = 'suspended' WHERE `subscription_status` IN ('trial_expired','grace_period')");
        DB::statement("ALTER TABLE `company_admins` MODIFY `subscription_status` ENUM('trial','active','suspended','cancelled','pending_payment') DEFAULT 'trial'");
    }
};
