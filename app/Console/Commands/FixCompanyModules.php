<?php

namespace App\Console\Commands;

use App\Models\CompanyAdmin;
use App\Models\CompanyModule;
use Illuminate\Console\Command;

class FixCompanyModules extends Command
{
    protected $signature = 'company:fix-modules
        {email : Company admin email}
        {categories* : Purchased module categories, e.g. sales invoice client_portal hr compliance finance projects}
        {--replace : Remove any existing module keys not in the given categories (default: only add missing ones)}
        {--dry-run}';

    protected $description = 'Grant a company the granular module keys for the categories it actually purchased (repairs registrations corrupted by category-key packages)';

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
        $email = $this->argument('email');
        $categories = $this->argument('categories');
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        $unknown = array_diff($categories, array_keys(self::CATEGORY_MODULES));
        if (!empty($unknown)) {
            $this->error('Unknown category key(s): ' . implode(', ', $unknown));
            $this->line('Valid categories: ' . implode(', ', array_keys(self::CATEGORY_MODULES)));
            return self::FAILURE;
        }

        $admin = CompanyAdmin::where('email', $email)->first();
        if (!$admin) {
            $this->error("No company admin found with email {$email}");
            return self::FAILURE;
        }

        $company = $admin->companies()->first();
        if (!$company) {
            $this->error("Admin {$email} has no company associated.");
            return self::FAILURE;
        }

        $target = collect($categories)
            ->flatMap(fn ($c) => self::CATEGORY_MODULES[$c])
            ->unique()
            ->values()
            ->all();

        $current = $company->modules()->pluck('module_key')->all();
        $missing = array_values(array_diff($target, $current));
        $extra   = array_values(array_diff($current, $target));

        $this->line("Company #{$company->id} \"{$company->name}\" (admin: {$email})");
        $this->line('  current: ' . (implode(', ', $current) ?: '(none)'));
        $this->line('  target:  ' . implode(', ', $target));
        $this->line('  missing: ' . (implode(', ', $missing) ?: '(none)'));
        $this->line('  extra:   ' . (implode(', ', $extra) ?: '(none)') . ($replace ? ' [will be removed]' : ' [kept — pass --replace to remove]'));

        if ($dryRun) {
            $this->info('[dry-run] No changes written.');
            return self::SUCCESS;
        }

        foreach ($missing as $key) {
            CompanyModule::create(['company_id' => $company->id, 'module_key' => $key, 'is_enabled' => true]);
        }

        if ($replace && !empty($extra)) {
            $company->modules()->whereIn('module_key', $extra)->delete();
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
