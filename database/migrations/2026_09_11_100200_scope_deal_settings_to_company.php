<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Deal Workflow (when a client's payment creates the project, and whether
 * a client may part-pay at all) moves from per-tenant to per-company, so it
 * matches the rest of Settings after 2026_09_11_100000_backfill_per_company_
 * settings.
 *
 * `company_admin_id` is kept, still populated, and still the fallback read
 * path for a company with no row of its own — CompanyDealSettings::
 * forCompany() leans on it — but its UNIQUE index has to go, since one tenant
 * now legitimately holds one row per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_deal_settings')) {
            return;
        }

        if (!Schema::hasColumn('company_deal_settings', 'company_id')) {
            Schema::table('company_deal_settings', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('company_admin_id')
                    ->constrained('companies')->cascadeOnDelete();
            });
        }

        $this->dropTenantUnique();

        Schema::table('company_deal_settings', function (Blueprint $table) {
            $table->unique('company_id');
        });

        $this->backfillPerCompany();
    }

    /**
     * Drops whatever UNIQUE index constrains company_admin_id, so one tenant
     * can hold one row per company.
     *
     * Found by COLUMN rather than by name: the expected Laravel-generated name
     * is company_deal_settings_company_admin_id_unique, but some deployments
     * of this app were seeded from a raw SQL snapshot (see
     * 2024_01_01_000004_create_companies_table's own note), where the index
     * may carry any name. Assuming the name and silently skipping on a
     * mismatch would leave the constraint in place and make the backfill below
     * fail on the second company of every tenant.
     *
     * company_admin_id also carries a foreign key, and MySQL requires an index
     * on any FK column — so a plain index has to exist before the unique one
     * can be dropped. Same dance as 2026_07_17_130000 / 2026_08_07_100000 on
     * company_payment_gateways.
     */
    private function dropTenantUnique(): void
    {
        $uniqueNames = collect(DB::select('SHOW INDEX FROM company_deal_settings'))
            ->filter(fn ($i) => $i->Column_name === 'company_admin_id' && (int) $i->Non_unique === 0)
            ->pluck('Key_name')
            ->unique()
            ->reject(fn ($name) => $name === 'PRIMARY')
            ->values();

        if ($uniqueNames->isEmpty()) {
            return;
        }

        $hasPlainIndex = collect(DB::select('SHOW INDEX FROM company_deal_settings'))
            ->contains(fn ($i) => $i->Column_name === 'company_admin_id' && (int) $i->Non_unique === 1);

        if (!$hasPlainIndex) {
            Schema::table('company_deal_settings', function (Blueprint $table) {
                $table->index('company_admin_id');
            });
        }

        foreach ($uniqueNames as $name) {
            Schema::table('company_deal_settings', function (Blueprint $table) use ($name) {
                $table->dropUnique($name);
            });
        }
    }

    /**
     * Give every company its own row, copied from whatever its tenant had
     * configured, so the read-path change lands with no behaviour change. The
     * original tenant row (company_id IS NULL) is left in place as the
     * fallback for a company created later but never saved.
     */
    private function backfillPerCompany(): void
    {
        $tenantRows = DB::table('company_deal_settings')
            ->whereNull('company_id')
            ->get()
            ->keyBy('company_admin_id');

        DB::table('companies')->orderBy('id')->chunkById(200, function ($companies) use ($tenantRows) {
            foreach ($companies as $company) {
                $alreadyOwns = DB::table('company_deal_settings')
                    ->where('company_id', $company->id)
                    ->exists();

                if ($alreadyOwns) {
                    continue;
                }

                $tenant = $tenantRows[$company->admin_id] ?? null;

                DB::table('company_deal_settings')->insert([
                    'company_admin_id'         => $company->admin_id,
                    'company_id'               => $company->id,
                    // The documented defaults when the tenant never saved the
                    // Deal Workflow tab — mirrors CompanyDealSettings::
                    // forCompany()'s firstOrNew() attributes.
                    'project_creation_trigger' => $tenant->project_creation_trigger ?? 'full_payment',
                    'payment_policy'           => $tenant->payment_policy ?? 'partial_allowed',
                    'allow_admin_override'     => $tenant->allow_admin_override ?? true,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_deal_settings')) {
            return;
        }

        DB::table('company_deal_settings')->whereNotNull('company_id')->delete();

        Schema::table('company_deal_settings', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('company_deal_settings', function (Blueprint $table) {
            $table->unique('company_admin_id');
        });
    }
};
