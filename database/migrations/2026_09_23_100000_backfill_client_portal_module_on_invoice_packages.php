<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every package that includes the Invoice module must also include Client —
 * ModuleDependency::errors() (backend) and moduleDependencyErrors()
 * (frontend/lib/moduleCategories.ts) both already enforce this, and
 * database/seeders/PackageSeeder.php already declares it in its own
 * 'categories' arrays ("Invoice requires Client (see ModuleDependency::
 * errors()) — every package that includes 'invoice' must also include
 * 'client_portal'."). What was missing was the actual `client_portal` row
 * in `package_modules` for six live packages (Starter/Business, all three
 * billing terms) — they carry `invoices`/`payments`/etc. but never got the
 * matching `client_portal` row, so registering under any of them correctly
 * tripped that same dependency check and refused to let anyone sign up.
 *
 * Fixed here as a plain data backfill rather than editing PackageSeeder's
 * output by hand, because it has to reach the LIVE `package_modules` rows
 * (already diverged from what the seeder would produce today), not just the
 * seed script.
 *
 * ONLY INSERTS. Never touches an existing row, never removes anything —
 * same convention as every other backfill migration in this codebase
 * (e.g. 2026_08_25_100100_backfill_lead_manager_view_all_invoices_
 * permission). Scoped generically (any package holding an invoice-category
 * module but missing client_portal), not to hardcoded package ids, so it
 * also covers a Super-Admin-created custom package that has the same gap,
 * present or future — and is a genuine no-op wherever the gap doesn't
 * exist (Enterprise already has client_portal and gains nothing here).
 *
 * ZERO retroactive effect on any already-registered company: a company's
 * own `company_modules` are a one-time copy taken at registration
 * (Api\PublicController::register()), never live-linked to `package_
 * modules` — this only changes what a NEW signup under one of these
 * packages receives going forward.
 */
return new class extends Migration
{
    private const INVOICE_MODULE_KEYS = ['invoices', 'payments', 'payment_details', 'invoice_reminders'];

    public function up(): void
    {
        if (!Schema::hasTable('package_modules') || !Schema::hasTable('packages')) {
            return;
        }

        $now = now();

        $packageIds = DB::table('package_modules')
            ->whereIn('module_key', self::INVOICE_MODULE_KEYS)
            ->distinct()
            ->pluck('package_id');

        $alreadyHasClient = DB::table('package_modules')
            ->where('module_key', 'client_portal')
            ->pluck('package_id')
            ->all();

        $rows = [];
        foreach ($packageIds as $packageId) {
            if (in_array($packageId, $alreadyHasClient, true)) {
                continue;
            }

            $rows[] = [
                'package_id' => $packageId,
                'module_key' => 'client_portal',
                'is_enabled' => true,
                'is_core'    => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            DB::table('package_modules')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Not reversed — rolling back would strip Client access from
        // whichever packages this added it to, which could mean removing it
        // from a package a company has ALREADY registered under between
        // this migration running and any rollback. Recovering a broken
        // registration flow is not something a `down()` should be able to
        // silently undo.
    }
};
