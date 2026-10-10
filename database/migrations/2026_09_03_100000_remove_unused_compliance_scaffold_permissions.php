<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The Compliance permission catalog used to list 18 keys, but only 3
// (canViewComplianceCases, canManageComplianceTemplates,
// canManageComplianceRequirements) are ever checked by any controller — the
// other 15 belonged to an earlier, unused Policy/Risk/Incident/Violation/
// Audit-Trail/Reports/Chat scaffold and were removed from ModuleCatalog.php
// and RoleDefaultPermissions.php. This deletes any already-granted rows for
// those dead keys so Edit User Permissions doesn't carry stale grants that
// no longer correspond to a real checkbox.
return new class extends Migration
{
    private const REMOVED_KEYS = [
        'canViewComplianceDashboard', 'canViewPolicies', 'canCreatePolicies', 'canEditPolicies',
        'canAssignPolicies', 'canViewAuditTrails', 'canExportAuditTrails', 'canViewComplianceReports',
        'canExportComplianceReports', 'canCreateRiskAssessments', 'canEditRiskAssessments',
        'canViewAlertsViolations', 'canResolveAlertsViolations', 'canManageDocumentCompliance',
        'canUseComplianceChat',
        // Also removed separately (see 2026-09-03 assignOfficer changes):
        // Compliance Officer assignment is Company Admin-only, not a
        // delegable staff permission.
        'canAssignComplianceOfficer',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('user_company_permissions')) {
            return;
        }

        DB::table('user_company_permissions')
            ->where('module_key', 'compliance')
            ->whereIn('permission_key', self::REMOVED_KEYS)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — these keys gated nothing and are no
        // longer part of the Compliance permission catalog.
    }
};
