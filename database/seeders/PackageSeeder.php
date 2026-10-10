<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\PackageModule;
use App\Services\YearlyPackageSync;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    // Mirrors the category → module expansion in frontend/app/register/page.tsx (CATEGORIES)
    // so packages built here unlock the same features the middleware/dashboard check for.
    private const CATEGORY_MODULES = [
        // 'clients' deliberately excluded — see ModuleSeeder.php's matching comment.
        'sales'         => ['leads', 'projects_handoff', 'lead_transfer', 'reports_seller'],
        'invoice'       => ['invoices', 'payments', 'payment_details', 'invoice_reminders'],
        'client_portal' => ['client_portal'],
        'hr'            => ['employees', 'recruitment', 'attendance', 'leaves', 'payroll'],
        'compliance'    => ['compliance', 'policies', 'audit_trails', 'compliance_reports', 'risk_assessments', 'alerts', 'document_compliance'],
        'finance'       => ['finance_dashboard', 'finance_reports', 'revenue_reports', 'payments_report'],
        'projects'      => ['projects', 'tasks', 'timesheets', 'production', 'revisions', 'deliverables', 'team_resources', 'file_storage'],
    ];

    private array $packages = [
        [
            'name'         => 'Starter',
            'tier'         => 'basic',
            'price'        => 4199,
            'price_pkr'    => 4199,
            'price_usd'    => 20,
            'billing_cycle'=> 'monthly',
            'trial_days'   => 14,
            'is_active'    => true,
            'is_visible'   => true,
            'is_popular'   => false,
            'max_companies'=> 1,
            'max_users_per_company' => 10,
            'description'  => 'Perfect for small businesses getting started.',
            'features'     => [
                'Up to 10 Users',
                'Sales & Invoicing',
                'Client Portal',
                'Email Support',
            ],
            // Invoice requires Client (see ModuleDependency::errors()) — every
            // package that includes 'invoice' must also include 'client_portal'.
            'categories' => ['sales', 'invoice', 'client_portal'],
        ],
        [
            'name'         => 'Business',
            'tier'         => 'professional',
            'price'        => 7199,
            'price_pkr'    => 7199,
            'price_usd'    => 35,
            'billing_cycle'=> 'monthly',
            'trial_days'   => 14,
            'is_active'    => true,
            'is_visible'   => true,
            'is_popular'   => true,
            'max_companies'=> 1,
            'max_users_per_company' => 50,
            'description'  => 'Best for growing teams that need more power.',
            'features'     => [
                'Up to 50 Users',
                'Everything in Starter',
                'HR Management',
                'Finance Dashboard',
                'Priority Support',
            ],
            // Invoice requires Client (see ModuleDependency::errors()) — every
            // package that includes 'invoice' must also include 'client_portal'.
            'categories' => ['sales', 'invoice', 'client_portal', 'hr', 'finance'],
        ],
        [
            'name'         => 'Enterprise',
            'tier'         => 'enterprise',
            'price'        => 9999,
            'price_pkr'    => 9999,
            'price_usd'    => 50,
            'billing_cycle'=> 'monthly',
            'trial_days'   => 14,
            'is_active'    => true,
            'is_visible'   => true,
            'is_popular'   => false,
            'max_companies'=> null,
            'max_users_per_company' => null,
            'description'  => 'Every module included. No limits.',
            'features'     => [
                'Unlimited Users',
                'All Modules Included',
                'Project Management',
                'Compliance Suite',
                'Priority Support',
                'Dedicated Account Manager',
            ],
            'categories' => ['sales', 'invoice', 'client_portal', 'hr', 'compliance', 'finance', 'projects'],
        ],
    ];

    public function run(): void
    {
        // Deliberately no upfront delete: Package::updateOrCreate() below keeps
        // existing packages' ids stable. Deleting first would cascade-delete
        // every company_admin on that package (package_id is cascadeOnDelete),
        // wiping demo/tenant data every time this seeder runs.
        foreach ($this->packages as $data) {
            $categories = $data['categories'];
            unset($data['categories']);
            $modules = array_values(array_unique(array_merge(
                ...array_map(fn($cat) => self::CATEGORY_MODULES[$cat], $categories)
            )));

            $package = Package::updateOrCreate(
                ['name' => $data['name']],
                $data
            );

            $package->modules()->delete();
            foreach ($modules as $key) {
                PackageModule::create([
                    'package_id' => $package->id,
                    'module_key' => $key,
                    'is_enabled' => true,
                    'is_core'    => false,
                ]);
            }

            // Keep yearly/multi-year companion rows in lockstep with this
            // monthly package's modules — otherwise they'd keep stale module
            // sets (e.g. Invoice without Client) until a Super Admin happens
            // to re-save the package or a billing term.
            YearlyPackageSync::syncOne($package->fresh('modules'));
        }
    }
}
