<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// company_admins.currency (the tenant-level, authoritative currency —
// Company::invoicingProfile()) defaulted to 'PKR' (see
// 2026_07_17_140000_add_tenant_business_profile_to_company_admins.php).
// Neither CompanyAdmin-creating path (Api\PublicController::register(),
// Api\SuperAdmin\CompanyAdminController::store()) ever sets 'currency'
// explicitly, so every new Company Admin silently started on PKR for
// invoicing regardless of what the signup form otherwise implied (it already
// hardcodes the legacy per-company `companies.currency` to 'USD' — see
// frontend/app/register/page.tsx). Raw SQL, not Schema::table()->change(),
// since doctrine/dbal isn't installed in this project.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_admins') || !Schema::hasColumn('company_admins', 'currency')) {
            return;
        }

        DB::statement("ALTER TABLE company_admins MODIFY currency VARCHAR(10) NOT NULL DEFAULT 'USD'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_admins') || !Schema::hasColumn('company_admins', 'currency')) {
            return;
        }

        DB::statement("ALTER TABLE company_admins MODIFY currency VARCHAR(10) NOT NULL DEFAULT 'PKR'");
    }
};
