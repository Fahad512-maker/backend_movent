<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    // Keep this expansion in sync with frontend/app/register/page.tsx (CATEGORIES)
    // and the DB_TO_CATALOG mapping in frontend/lib/moduleCatalog.ts / app/Services/ModuleCatalog.php.
    private array $modules = [
        // 'clients' is deliberately NOT in this list — Sales buyers get only the
        // limited "Basic Clients" permission bundle (see ModuleCatalog.php's
        // canViewClients/canCreateClients/canEditClients), not the full Client
        // module (client CRUD list, portal, support tickets). Adding 'clients'
        // here previously activated the real module for anyone buying Sales.
        ['key' => 'sales',         'label' => 'Sales',         'description' => 'Leads, clients & pipeline',        'price_pkr' => 1500, 'price_usd' => 6, 'sub_modules' => ['leads', 'projects_handoff', 'lead_transfer', 'reports_seller']],
        ['key' => 'invoice',       'label' => 'Invoice',       'description' => 'Billing, payments & reminders',    'price_pkr' => 1200, 'price_usd' => 5, 'sub_modules' => ['invoices', 'payments', 'payment_details', 'invoice_reminders']],
        ['key' => 'client_portal', 'label' => 'Client Portal', 'description' => 'Client login, documents & support','price_pkr' => 1200, 'price_usd' => 5, 'sub_modules' => ['client_portal']],
        ['key' => 'hr',            'label' => 'HR',            'description' => 'Employees, attendance & payroll', 'price_pkr' => 1800, 'price_usd' => 7, 'sub_modules' => ['employees', 'recruitment', 'attendance', 'leaves', 'payroll']],
        ['key' => 'compliance',    'label' => 'Compliance',    'description' => 'Policies, audits & risk',         'price_pkr' => 1500, 'price_usd' => 6, 'sub_modules' => ['compliance', 'policies', 'audit_trails', 'compliance_reports', 'risk_assessments', 'alerts', 'document_compliance']],
        ['key' => 'finance',       'label' => 'Finance',       'description' => 'Dashboard, revenue & reports',    'price_pkr' => 1200, 'price_usd' => 5, 'sub_modules' => ['finance_dashboard', 'finance_reports', 'revenue_reports', 'payments_report']],
        ['key' => 'projects',      'label' => 'Projects',      'description' => 'Tasks, timesheets & deliverables','price_pkr' => 1800, 'price_usd' => 7, 'sub_modules' => ['projects', 'tasks', 'timesheets', 'production', 'revisions', 'deliverables', 'team_resources', 'file_storage']],
    ];

    public function run(): void
    {
        foreach ($this->modules as $data) {
            Module::updateOrCreate(
                ['key' => $data['key']],
                [...$data, 'is_active' => true, 'is_system' => true]
            );
        }
    }
}
