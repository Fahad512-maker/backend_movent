<?php

namespace App\Services;

class ModuleDependency
{
    // Mirrors the category expansion in ModuleSeeder.php — keep in sync.
    private const CATEGORY_MODULES = [
        'sales'      => ['sales', 'leads', 'projects_handoff', 'lead_transfer', 'reports_seller'],
        'client'     => ['client', 'client_portal', 'client_documents', 'client_chat', 'client_support'],
        'projects'   => ['projects', 'tasks', 'timesheets', 'production', 'revisions', 'deliverables', 'team_resources', 'file_storage'],
        'finance'    => ['finance', 'finance_dashboard', 'finance_reports', 'revenue_reports', 'payments_report'],
        'invoice'    => ['invoice', 'invoices', 'payments', 'payment_details', 'invoice_reminders'],
        'compliance' => ['compliance', 'policies', 'audit_trails', 'compliance_reports', 'risk_assessments', 'alerts', 'document_compliance'],
    ];

    // $newKeys, when given, scopes the Invoice-requires-Client check to only
    // fire when Invoice is part of THIS registration/purchase — companies
    // that already owned Invoice before this rule existed are grandfathered
    // and won't be blocked from buying unrelated modules later. Defaults to
    // $moduleKeys (everything counts as "new"), which is correct for
    // registration, where there's no pre-existing ownership to grandfather.
    public static function errors(array $moduleKeys, ?array $newKeys = null): array
    {
        $categories = self::categories($moduleKeys);
        $newCategories = $newKeys !== null ? self::categories($newKeys) : $categories;
        $errors = [];

        if (in_array('sales', $categories, true) && !in_array('invoice', $categories, true)) {
            $errors[] = 'Invoice module is required because Sales includes invoice features.';
        }

        if (in_array('client', $categories, true)
            && !in_array('invoice', $categories, true)
            && !in_array('projects', $categories, true)) {
            $errors[] = 'Client module requires Invoice or Project.';
        }

        if (in_array('finance', $categories, true) && !in_array('invoice', $categories, true)) {
            $errors[] = 'Invoice module is required because Finance depends on invoice and payment data.';
        }

        if (in_array('invoice', $newCategories, true) && !in_array('client', $categories, true)) {
            $errors[] = 'Client module is required because Invoice needs a client to bill.';
        }

        if (in_array('compliance', $categories, true)
            && (!in_array('projects', $categories, true) || !in_array('invoice', $categories, true) || !in_array('finance', $categories, true))) {
            $errors[] = 'Compliance requires the Project, Invoice, and Finance modules.';
        }

        return $errors;
    }

    public static function categories(array $moduleKeys): array
    {
        $categories = [];
        foreach (self::CATEGORY_MODULES as $category => $keys) {
            if (!empty(array_intersect($moduleKeys, $keys))) {
                $categories[] = $category;
            }
        }

        return $categories;
    }
}
