<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings' Company/Invoice/Bank tabs move BACK from per-tenant to
 * per-company — the reverse of 2026_07_17_140000_add_tenant_business_profile_
 * to_company_admins, which had collapsed every company an admin owns onto one
 * shared invoicing identity. That sharing is the bug being fixed: editing
 * Settings while Company A was selected silently rewrote Company B's invoice
 * prefix, bank details, tax rate and business profile too.
 *
 * No new columns are needed — `companies` has carried every one of these
 * fields since 2024_01_01_000004 (profile) and 2026_06_24_000001 (invoice +
 * bank). They simply stopped being READ when Company::invoicingProfile()
 * started preferring the tenant row, so they now hold values last written
 * before 2026-07-17 and are stale.
 *
 * This copies each tenant's CURRENT (and therefore live, effective) profile
 * into every one of its company rows, so the precedence flip in
 * Company::invoicingProfile() changes nothing on the day it ships — every
 * company keeps invoicing exactly as it did — and the two only diverge once
 * someone actually edits one company's Settings.
 *
 * Two columns are deliberately NOT copied:
 *   • companies.name — the whole point of the flip. company_admins.
 *     business_name was itself only ever a copy of ONE company's name (see
 *     that migration's backfillFromFirstCompany(), which picked the most
 *     recently updated company and applied its name to the tenant), and that
 *     one name then appeared on every company's invoices. Each company's own
 *     name is authoritative again.
 *   • companies.currency — already per-company and already authoritative; it
 *     is the field the rest of the profile is being brought in line with.
 */
return new class extends Migration
{
    public function up(): void
    {
        // companies.invoice_payment_terms is unsignedTinyInteger (max 255)
        // while company_admins.invoice_payment_terms is a full integer and
        // SettingsController validates it up to 365 — so a tenant on
        // "365 day" terms could not be represented per-company at all, and
        // the backfill below would truncate. doctrine/dbal isn't installed,
        // so this goes through raw SQL rather than Blueprint::change().
        if (Schema::hasColumn('companies', 'invoice_payment_terms')) {
            DB::statement('ALTER TABLE companies MODIFY invoice_payment_terms SMALLINT UNSIGNED NOT NULL DEFAULT 30');
        }

        $this->backfillFromTenant();
    }

    private function backfillFromTenant(): void
    {
        DB::table('company_admins')->orderBy('id')->chunkById(100, function ($admins) {
            foreach ($admins as $admin) {
                DB::table('companies')->where('admin_id', $admin->id)->update([
                    'industry'              => $admin->industry,
                    'email'                 => $admin->business_email,
                    'phone'                 => $admin->business_phone,
                    'address'               => $admin->address,
                    'timezone'              => $admin->timezone ?: 'Asia/Karachi',
                    'logo_path'             => $admin->logo_path,
                    'invoice_prefix'        => $admin->invoice_prefix ?: 'INV',
                    'invoice_tax_rate'      => $admin->invoice_tax_rate ?? 0,
                    // Clamp defensively: the old column allowed up to 365 and
                    // the widened one allows far more, but a nonsense value
                    // from an unvalidated write shouldn't land here.
                    'invoice_payment_terms' => min(max((int) ($admin->invoice_payment_terms ?? 30), 0), 365),
                    'invoice_notes'         => $admin->invoice_notes,
                    'bank_name'             => $admin->bank_name,
                    'bank_account_name'     => $admin->bank_account_name,
                    'bank_account_number'   => $admin->bank_account_number,
                    'bank_iban'             => $admin->bank_iban,
                    'bank_swift'            => $admin->bank_swift,
                    'updated_at'            => now(),
                ]);
            }
        });
    }

    /**
     * Nothing to undo: the tenant columns this copied FROM are untouched and
     * still hold every value, and reverting Company::invoicingProfile()'s
     * precedence is what actually restores the old behaviour. Narrowing
     * invoice_payment_terms back to a tinyint would corrupt any company now
     * legitimately on terms above 255 days, so it is left widened.
     */
    public function down(): void
    {
        //
    }
};
