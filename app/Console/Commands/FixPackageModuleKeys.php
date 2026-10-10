<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\PackageModule;
use Illuminate\Console\Command;

class FixPackageModuleKeys extends Command
{
    protected $signature = 'packages:fix-module-keys {--dry-run}';

    protected $description = 'Expand category-level module keys (e.g. "sales", "hr") stored on packages into the granular keys the app actually checks (e.g. "leads", "employees")';

    // Mirrors database/seeders/ModuleSeeder.php + database/seeders/PackageSeeder.php
    private const CATEGORY_MODULES = [
        // 'clients' deliberately excluded — see database/seeders/ModuleSeeder.php's matching comment.
        'sales'         => ['leads', 'projects_handoff', 'lead_transfer', 'reports_seller'],
        'invoice'       => ['invoices', 'payments', 'payment_details', 'invoice_reminders'],
        'client_portal' => ['client_portal'],
        'hr'            => ['employees', 'recruitment', 'attendance', 'leaves', 'payroll'],
        'compliance'    => ['compliance', 'policies', 'audit_trails', 'compliance_reports', 'risk_assessments', 'alerts', 'document_compliance'],
        'finance'       => ['finance_dashboard', 'finance_reports', 'revenue_reports', 'payments_report'],
        'projects'      => ['projects', 'tasks', 'timesheets', 'production', 'revisions', 'deliverables', 'team_resources', 'file_storage'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $packages = Package::with('modules')->get();
        $touched = 0;

        foreach ($packages as $package) {
            $currentKeys = $package->modules->pluck('module_key')->all();

            $granular = collect($currentKeys)
                ->flatMap(fn ($key) => self::CATEGORY_MODULES[$key] ?? [$key])
                ->unique()
                ->values()
                ->all();

            // Only touch packages where expansion actually changes the set —
            // avoids false positives on granular keys that happen to share a
            // name with a category (e.g. "client_portal", "compliance").
            $same = empty(array_diff($currentKeys, $granular)) && empty(array_diff($granular, $currentKeys));
            if ($same) {
                continue;
            }

            $this->line("Package #{$package->id} \"{$package->name}\"");
            $this->line('  before: ' . implode(', ', $currentKeys));
            $this->line('  after:  ' . implode(', ', $granular));

            if (!$dryRun) {
                $package->modules()->delete();
                foreach ($granular as $key) {
                    PackageModule::create([
                        'package_id' => $package->id,
                        'module_key' => $key,
                        'is_enabled' => true,
                        'is_core'    => false,
                    ]);
                }
            }

            $touched++;
        }

        if ($touched === 0) {
            $this->info('No packages had category-level module keys — nothing to fix.');
        } else {
            $this->info(($dryRun ? '[dry-run] Would fix ' : 'Fixed ') . "{$touched} package(s).");
        }

        return self::SUCCESS;
    }
}
