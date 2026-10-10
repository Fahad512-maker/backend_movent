<?php

namespace App\Console\Commands;

use App\Models\CompanyAdmin;
use App\Models\CompanyModule;
use Illuminate\Console\Command;

/**
 * One-time repair for multi-company admins after module access became
 * per-active-company (CheckCompanyModule).
 *
 * Add-on purchases (Api\Admin\ModulePurchaseController) used to write their
 * company_modules rows to the admin's FIRST company only. That was invisible
 * while the admin gate unioned every owned company; now that the gate checks
 * the company picked in the Company Selector, those admins would lose the
 * add-on on their other companies. This copies the first company's enabled
 * keys onto every sibling company that has no row for that key at all.
 *
 * Deliberately additive only: a key the sibling already has a row for —
 * including a row with is_enabled = false — is left exactly as it is, so a
 * per-company choice made on purpose is never overridden. Dry run unless
 * --apply is passed.
 */
class SyncAdminCompanyModules extends Command
{
    protected $signature = 'company:sync-admin-modules
        {--admin= : Only this company admin (email)}
        {--apply : Write the changes (default is a dry run)}';

    protected $description = "Copy a multi-company admin's first-company module keys onto their other companies that are missing them";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $admins = CompanyAdmin::query()
            ->when($this->option('admin'), fn ($q, $email) => $q->where('email', $email))
            ->with(['companies' => fn ($q) => $q->orderBy('id'), 'companies.modules'])
            ->get()
            ->filter(fn ($a) => $a->companies->count() > 1);

        if ($admins->isEmpty()) {
            $this->info('No multi-company admins found — nothing to do.');
            return self::SUCCESS;
        }

        $totalAdded = 0;

        foreach ($admins as $admin) {
            // Same "first company" ModulePurchaseController priced against
            // ($admin->companies()->first()), resolved from the eager load.
            $primary = $admin->companies->first();
            $primaryKeys = $primary->modules->where('is_enabled', true)->pluck('module_key')->unique()->values();

            foreach ($admin->companies->skip(1) as $company) {
                $existing = $company->modules->pluck('module_key')->all();
                $missing  = $primaryKeys->diff($existing)->values();

                if ($missing->isEmpty()) {
                    continue;
                }

                $this->line("{$admin->email}: company #{$company->id} \"{$company->name}\" ← from #{$primary->id}: " . $missing->implode(', '));
                $totalAdded += $missing->count();

                if ($apply) {
                    foreach ($missing as $key) {
                        CompanyModule::create(['company_id' => $company->id, 'module_key' => $key, 'is_enabled' => true]);
                    }
                }
            }
        }

        if ($totalAdded === 0) {
            $this->info('Every sibling company already has every first-company key — nothing to do.');
        } elseif ($apply) {
            $this->info("Done. {$totalAdded} module row(s) added.");
        } else {
            $this->info("[dry-run] {$totalAdded} module row(s) would be added. Re-run with --apply to write them.");
        }

        return self::SUCCESS;
    }
}
