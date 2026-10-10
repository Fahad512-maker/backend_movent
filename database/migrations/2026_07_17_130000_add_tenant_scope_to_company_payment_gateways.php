<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment gateways move from per-company to per-tenant (company_admin)
     * ownership — a Company Admin activates a gateway once and every company
     * under their account shares it. This adds `company_admin_id` alongside
     * the existing `company_id` (kept, nullable) rather than replacing the
     * table, so old company-scoped rows keep working as a fallback until
     * every tenant has an explicit tenant-level row (see
     * CompanyPaymentGateway::resolveActiveGateways()).
     */
    public function up(): void
    {
        Schema::table('company_payment_gateways', function (Blueprint $table) {
            $table->foreignId('company_admin_id')->nullable()->after('id')->constrained('company_admins')->cascadeOnDelete();
        });

        // The (company_id, gateway) unique index is also the FK's supporting
        // index — give company_id a plain index first so MySQL can drop the
        // composite unique without complaining the FK has nothing to lean on.
        Schema::table('company_payment_gateways', function (Blueprint $table) {
            $table->index('company_id');
        });

        Schema::table('company_payment_gateways', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'gateway']);
            $table->unique(['company_admin_id', 'gateway']);
        });

        // doctrine/dbal isn't installed, so column-type changes go through raw
        // SQL instead of Blueprint::change(). company_id becomes nullable
        // (tenant-scoped rows have none); config moves from JSON to TEXT
        // because encrypted ciphertext (see encryptExistingConfig()) isn't
        // valid JSON and MySQL's JSON column type rejects it.
        DB::statement('ALTER TABLE company_payment_gateways MODIFY company_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE company_payment_gateways MODIFY config TEXT NULL');

        $this->backfillTenantRows();
        $this->encryptExistingConfig();
    }

    /**
     * For every company_admin, collapse their companies' existing
     * company-scoped gateway rows into one tenant-scoped row per gateway.
     * If a tenant's companies disagree on credentials for the same gateway,
     * the most recently updated row wins and the conflict is logged instead
     * of being silently discarded — nothing is deleted.
     */
    private function backfillTenantRows(): void
    {
        $rows = DB::table('company_payment_gateways')
            ->whereNull('company_admin_id')
            ->whereNotNull('company_id')
            ->get();

        $companyToAdmin = DB::table('companies')->pluck('admin_id', 'id');

        $grouped = $rows->groupBy(function ($row) use ($companyToAdmin) {
            $adminId = $companyToAdmin[$row->company_id] ?? null;
            return $adminId ? "{$adminId}:{$row->gateway}" : null;
        })->except([null]);

        foreach ($grouped as $key => $group) {
            [$adminId, $gateway] = explode(':', $key, 2);

            if (DB::table('company_payment_gateways')->where('company_admin_id', $adminId)->where('gateway', $gateway)->exists()) {
                continue;
            }

            $distinctConfigs = $group->pluck('config')->unique()->values();
            if ($distinctConfigs->count() > 1) {
                Log::warning('[migration] company_payment_gateways: conflicting per-company credentials found for one tenant — using the most recently updated row.', [
                    'company_admin_id' => $adminId,
                    'gateway'          => $gateway,
                    'company_ids'      => $group->pluck('company_id')->all(),
                ]);
            }

            $winner = $group->sortByDesc('updated_at')->first();

            DB::table('company_payment_gateways')->insert([
                'company_admin_id' => $adminId,
                'company_id'       => null,
                'gateway'          => $gateway,
                'is_active'        => $winner->is_active,
                'config'           => $winner->config,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    /**
     * The `config` column moves to Laravel's `encrypted:array` cast (see the
     * model) so secrets are encrypted at rest. Existing rows were written as
     * plain JSON under the old `array` cast — re-encrypt them in place so the
     * new cast can read every row, old and newly-backfilled alike.
     */
    private function encryptExistingConfig(): void
    {
        DB::table('company_payment_gateways')->orderBy('id')->get()->each(function ($row) {
            if (!$row->config) return;

            // Already encrypted (e.g. re-running this migration) — leave alone.
            try {
                Crypt::decryptString($row->config);
                return;
            } catch (\Throwable) {
                // not encrypted yet — fall through and encrypt it
            }

            DB::table('company_payment_gateways')
                ->where('id', $row->id)
                ->update(['config' => Crypt::encryptString($row->config)]);
        });
    }

    public function down(): void
    {
        Schema::table('company_payment_gateways', function (Blueprint $table) {
            $table->dropForeign(['company_admin_id']);
            $table->dropUnique(['company_admin_id', 'gateway']);
            $table->dropColumn('company_admin_id');
        });

        Schema::table('company_payment_gateways', function (Blueprint $table) {
            $table->unique(['company_id', 'gateway']);
        });
    }
};
