<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manual, per-Company suspension — deliberately separate from is_active
// (Super Admin's own toggle, see SuperAdmin\CompanyController::toggleStatus())
// and from company_admins.subscription_status (tenant-wide billing lifecycle,
// see CompanyAdmin::isBlockedFromAccess()). All three compose (any one of them
// blocks access) but must never be conflated into the same column, so
// reactivating a manual suspension can never accidentally lift a Super Admin
// deactivation or a billing block — see Api\Admin\ClientController::
// reactivateCompany().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
