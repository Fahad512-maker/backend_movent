<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings' Company/Invoice/Bank tabs move from per-company to per-tenant,
     * the same way payment gateways did — one invoicing identity for the
     * whole Company Admin account, shared by every company under it. This is
     * distinct from `companies.name` (kept as-is, still used to tell
     * companies apart when managing them) and distinct from
     * `company_admins.name/email/phone` (the admin's own login identity) —
     * hence the `business_*` naming for the new columns, to avoid colliding
     * with either.
     */
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->string('business_name', 200)->nullable()->after('name');
            $table->string('industry', 100)->nullable()->after('business_name');
            $table->string('business_email', 255)->nullable()->after('industry');
            $table->string('business_phone', 30)->nullable()->after('business_email');
            $table->text('address')->nullable()->after('business_phone');
            $table->string('timezone', 60)->default('Asia/Karachi')->after('address');
            $table->string('currency', 10)->default('PKR')->after('timezone');
            $table->string('logo_path', 600)->nullable()->after('currency');

            $table->string('invoice_prefix', 20)->default('INV')->after('logo_path');
            $table->decimal('invoice_tax_rate', 5, 2)->default(0)->after('invoice_prefix');
            $table->integer('invoice_payment_terms')->default(30)->after('invoice_tax_rate');
            $table->text('invoice_notes')->nullable()->after('invoice_payment_terms');

            $table->string('bank_name', 100)->nullable()->after('invoice_notes');
            $table->string('bank_account_name', 150)->nullable()->after('bank_name');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_name');
            $table->string('bank_iban', 50)->nullable()->after('bank_account_number');
            $table->string('bank_swift', 20)->nullable()->after('bank_iban');
        });

        $this->backfillFromFirstCompany();
    }

    /**
     * Seed each tenant's new business profile from their companies' existing
     * data so nothing appears blank after migrating. Nothing on `companies`
     * is touched or deleted — this only copies. When a tenant's companies
     * disagree (different name/address/bank details, which is expected and
     * legitimate today), the most recently updated company's values win and
     * the conflict is logged rather than silently discarded.
     */
    private function backfillFromFirstCompany(): void
    {
        $companies = DB::table('companies')->orderByDesc('updated_at')->get()->groupBy('admin_id');

        foreach ($companies as $adminId => $group) {
            $distinctNames = $group->pluck('name')->unique();
            if ($distinctNames->count() > 1) {
                Log::warning('[migration] company_admins business profile backfill: companies under one tenant have different names — using the most recently updated one.', [
                    'company_admin_id' => $adminId,
                    'company_names'    => $distinctNames->values()->all(),
                ]);
            }

            $winner = $group->first(); // already sorted by updated_at desc

            DB::table('company_admins')->where('id', $adminId)->update([
                'business_name'         => $winner->name,
                'industry'              => $winner->industry,
                'business_email'        => $winner->email,
                'business_phone'        => $winner->phone,
                'address'               => $winner->address,
                'timezone'              => $winner->timezone ?? 'Asia/Karachi',
                'currency'              => $winner->currency ?? 'PKR',
                'logo_path'             => $winner->logo_path,
                'invoice_prefix'        => $winner->invoice_prefix ?? 'INV',
                'invoice_tax_rate'      => $winner->invoice_tax_rate ?? 0,
                'invoice_payment_terms' => $winner->invoice_payment_terms ?? 30,
                'invoice_notes'         => $winner->invoice_notes,
                'bank_name'             => $winner->bank_name,
                'bank_account_name'     => $winner->bank_account_name,
                'bank_account_number'   => $winner->bank_account_number,
                'bank_iban'             => $winner->bank_iban,
                'bank_swift'            => $winner->bank_swift,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->dropColumn([
                'business_name', 'industry', 'business_email', 'business_phone', 'address',
                'timezone', 'currency', 'logo_path',
                'invoice_prefix', 'invoice_tax_rate', 'invoice_payment_terms', 'invoice_notes',
                'bank_name', 'bank_account_name', 'bank_account_number', 'bank_iban', 'bank_swift',
            ]);
        });
    }
};
