<?php

namespace App\Services;

class ModuleCatalog
{
    public static function all(): array
    {
        return [
            // Merges "clients" + "client_portal" DB modules into one section
            'client' => [
                'name'        => 'Clients',
                'permissions' => [
                    // Client Management
                    'canViewClients', 'canCreateClients', 'canEditClients', 'canDeleteClients',
                    // Data scope override — without it, a non-admin user only
                    // sees clients they're the account manager for, or that are
                    // linked to their own lead/invoice/project (mirrors
                    // canViewAllCompanyLeads/canViewAllCompanyProjects).
                    'canViewAllCompanyClients',
                    // Reassign a client's account manager to another Seller —
                    // mirrors canTransferLeads (Api\User\ClientController::
                    // transfer()). A Company Admin can always do this
                    // regardless of this permission (Api\Admin\ClientController
                    // has no permission gate at all, same split as leads).
                    'canTransferClients',
                    // Client Portal Access
                    'canEnableClientPortal', 'canDisableClientPortal',
                    'canCreateClientLogin', 'canResetClientPassword', 'canManageClientAccess',
                    // Client Invoices
                    'canViewClientInvoices', 'canViewClientPayments',
                    // Client Documents
                    'canViewClientDocuments', 'canManageClientDocuments',
                    // Client Support
                    'canViewClientSupport', 'canManageClientSupport',
                ],
            ],
            'sales' => [
                'name'        => 'Sales',
                'permissions' => [
                    'canViewSalesDashboard', 'canViewLeads', 'canCreateLeads', 'canEditLeads',
                    'canDeleteLeads', 'canTransferLeads', 'canManagePipeline', 'canAssignLeadOwner',
                    'canViewSalesTargets', 'canUpdateSalesTargets', 'canManageSalesTargets', 'canViewSalesReports',
                    'canExportSalesReports', 'canAddLeadNotes', 'canUseSalesChat',
                    // Data scope override — without it, a Seller only sees leads
                    // assigned to them (mirrors canViewAllCompanyProjects).
                    'canViewAllCompanyLeads',
                    // Basic client access included with Sales
                    'canViewClients', 'canCreateClients', 'canEditClients',
                ],
            ],
            'invoice' => [
                'name'        => 'Invoice',
                'permissions' => [
                    'canViewInvoiceDashboard', 'canViewInvoices', 'canCreateInvoices',
                    'canEditInvoices', 'canDeleteOrCancelInvoices', 'canSendInvoices',
                    'canDownloadOrExportInvoices', 'canViewPayments', 'canRecordPayments',
                    'canSendPaymentReminders', 'canManageBillingClients', 'canViewInvoiceReports',
                    'canManageInvoiceSettings', 'canUseInvoiceChat',
                    // Brands — the trading names a company bills under (see
                    // App\Models\Brand). Not part of any role's defaults:
                    // Company Admin grants these to whoever maintains the
                    // brand list, which is rarely the same person who raises
                    // the invoices.
                    'canViewBrands', 'canCreateBrands', 'canEditBrands', 'canDeleteBrands',
                    // Data scope override — without it, a non-admin user only
                    // sees invoices they personally created, or that are tied
                    // to their own lead/client/project (mirrors
                    // canViewAllCompanyLeads/Clients/Projects). See
                    // Api\User\InvoiceController::visibleInvoices().
                    'canViewAllCompanyInvoices',
                    // Whether this user can change which company payment-gateway
                    // account(s) an invoice accepts, or override the tenant's
                    // per-type default selection — without it, invoice creation
                    // silently uses the company default gateway(s) (see
                    // Api\User\InvoiceController::store()). Company Admin always
                    // has this implicitly (admin guard bypasses permission checks).
                    'canSelectInvoiceGateway',
                ],
            ],
            'hr' => [
                'name'        => 'HR',
                // Extended 2026-09-21 to cover the full HR structure (Dashboard/
                // Employees/Attendance/Leaves/Payroll/Recruitment/Reports/
                // Notifications) — canViewAttendance/canUpdateAttendance,
                // canApplyLeave, canUpdatePayroll, canCreateRecruitment/
                // canUpdateRecruitment and canViewHRNotifications didn't exist
                // before. Additive only: every key already in use keeps its
                // name, so no existing grant is invalidated.
                'permissions' => [
                    'canViewHRDashboard', 'canViewEmployees', 'canCreateEmployees',
                    'canEditEmployees', 'canDeleteEmployees',
                    // Deactivate/Reactivate used to ride on canEditEmployees
                    // (a default HR permission), so every HR user implicitly
                    // got the ability to terminate/restore an employee's
                    // status for free. Split out, and deliberately NOT in
                    // RoleDefaultPermissions' hr defaults (nor
                    // frontend/lib/roleUtils.ts's mirror) — Admin-only
                    // unless explicitly granted.
                    'canDeactivateEmployees',
                    // Same split-out reasoning as canDeactivateEmployees
                    // above (Document upload/delete used to ride on
                    // canEditEmployees too) — but IS in the hr defaults
                    // (RoleDefaultPermissions.php / roleUtils.ts), unlike
                    // the others on this list, per explicit request.
                    'canManageEmployeeDocuments',
                    'canViewAttendance', 'canUpdateAttendance',
                    // Deliberately NOT in RoleDefaultPermissions' hr defaults
                    // (nor frontend/lib/roleUtils.ts's mirror) — this gates
                    // Attendance Policy (worked-hours thresholds + salary
                    // deduction %/free-allowance rules), which affects payroll
                    // for every employee, so it stays Admin-only until a
                    // Company Admin explicitly grants it to a staff user.
                    'canManageAttendancePolicy',
                    // Same reasoning, same exclusion from defaults — Shift
                    // CRUD used to ride on canCreate/Edit/DeleteEmployees
                    // (which ARE default HR permissions), so every HR user
                    // implicitly got shift-management for free. Split out so
                    // it's Admin-only unless explicitly granted.
                    'canManageShifts',
                    // Same exclusion-from-defaults reasoning — Department
                    // CRUD affects the department list every employee/
                    // recruitment record draws from, so it's deliberately
                    // NOT in RoleDefaultPermissions' hr defaults (nor
                    // frontend/lib/roleUtils.ts's mirror) — Admin-only until
                    // explicitly granted to a staff user.
                    'canManageDepartments',
                    'canViewRecruitment', 'canCreateRecruitment', 'canUpdateRecruitment',
                    'canManageRecruitment',
                    'canViewPayroll', 'canUpdatePayroll', 'canProcessPayroll',
                    'canViewLeave', 'canApplyLeave', 'canApproveLeave',
                    'canViewHRReports', 'canExportHRReports',
                    'canViewHRNotifications', 'canUseHRChat',
                ],
            ],
            'compliance' => [
                'name'        => 'Compliance',
                // Simplified 5-key model (2026-09-03) — every key here is
                // actually checked by Api\User\ComplianceController:
                // - canViewCompliance: view the module + cases/projects the
                //   user is tied to (officer, PM, seller, or team member).
                // - canViewAllCompanyCompliance: removes that restriction,
                //   sees every case in the active company.
                // - canAssignComplianceUser: assign/change a case's
                //   Compliance Officer.
                // - canChangeComplianceStatus: the case-workflow bucket —
                //   add/edit/delete requirements, upload/review documents,
                //   case status transitions, checklist, case comments.
                // - canDownloadComplianceData: download documents,
                //   attachments, deliveries, and the chat export.
                // Template management (create/edit/delete/set-default) is
                // Company Admin-only, not represented here.
                'permissions' => [
                    'canViewCompliance', 'canViewAllCompanyCompliance',
                    'canAssignComplianceUser', 'canChangeComplianceStatus',
                    'canDownloadComplianceData',
                ],
            ],
            // The Finance Area (Dashboard / Invoices / Payments / Payment
            // Details / Invoice Reminders / Reports). `requires: invoice`
            // because every figure it shows is derived from the invoices and
            // payments tables — there is nothing to report on without them.
            //
            // Naming follows this catalog's own convention (camelCase canXxx
            // under a module key), NOT a dotted finance.invoices.view scheme:
            // user_company_permissions stores (module_key, permission_key)
            // pairs and every can() check in the app reads that shape.
            //
            // Note the overlap with the 'invoice' module above:
            // canViewPayments/canRecordPayments exist in BOTH. That is
            // deliberate and predates this module — the same capability
            // reached from two different areas, granted independently, so a
            // Company Admin can give someone the Finance Area without giving
            // them the Invoice module (and vice versa). Every endpoint that
            // serves both areas accepts either key; see
            // App\Services\Finance\FinanceContext::canAny().
            'finance' => [
                'name'        => 'Finance',
                'requires'    => ['invoice'],
                'permissions' => [
                    // Finance Dashboard
                    'canViewFinanceDashboard', 'canViewRevenueDashboard',
                    // Finance > Invoices. View/Create/Update/Export, each its
                    // own key per spec. The create/update keys gate the SAME
                    // invoice endpoints the Invoice module's
                    // canCreateInvoices/canEditInvoices do — a Finance user
                    // holding these creates a perfectly ordinary invoice, not
                    // a parallel "finance invoice".
                    'canViewFinanceInvoices', 'canCreateFinanceInvoices',
                    'canUpdateFinanceInvoices', 'canExportFinanceInvoices',
                    // Finance > Payments
                    'canViewPayments', 'canRecordPayments', 'canReconcilePayments',
                    // Finance > Payment Details. canReconcilePaymentDetails is
                    // the NARROWER of the two reconcile keys: it unlocks
                    // reconciling from the payment-detail screen only, for
                    // someone who should verify individual payments they are
                    // looking at without getting the bulk reconcile action on
                    // the payments list (which needs canReconcilePayments).
                    // canReconcilePayments implies it.
                    'canViewPaymentDetails', 'canReconcilePaymentDetails', 'canExportPaymentDetails',
                    // Data scope override, same family as
                    // canViewAllCompanyLeads/Clients/Invoices/Projects. WITHOUT
                    // it the Finance Area shows only the invoices (and their
                    // payments/reminders) this person is already tied to —
                    // created_by, their lead, their client, their project —
                    // exactly the rule Api\User\InvoiceController::
                    // visibleInvoices() already applies. A Seller granted
                    // Finance by default does NOT get this, so opening the
                    // Finance Area never widens what a Seller can see; the
                    // Finance User role does, because seeing the whole
                    // company's money is the job.
                    'canViewAllCompanyFinance',
                    // Finance > Invoice Reminders
                    'canCreateInvoiceReminders', 'canSendInvoiceReminders', 'canTrackInvoiceReminders',
                    // Finance > Reports — one view/export pair per report.
                    'canViewFinanceReports', 'canExportFinanceReports',
                    'canViewRevenueReports', 'canExportRevenueReports',
                    'canViewPaymentReports', 'canExportPaymentReports',
                    // Declared since the module was first scaffolded; no
                    // Finance-specific chat surface exists yet, so nothing
                    // reads it. Kept because dropping a catalog key makes any
                    // existing user_company_permissions row referencing it
                    // fail isValidPermission() and vanish on the next save.
                    'canUseFinanceChat',
                ],
            ],
            'project_management' => [
                'name'        => 'Project Management',
                'permissions' => [
                    'canViewProjectDashboard', 'canViewProjects', 'canCreateProjects',
                    // Narrower than canCreateProjects — lets a Seller create ONLY a
                    // handoff project from their own won lead (requires Sales +
                    // Project Management both active), not a general "create any
                    // project" right. See Api\User\ProjectController::store().
                    'canCreateProjectHandoff',
                    // Everything invoice-related on a project — viewing linked
                    // invoices, the billing summary (total invoiced/paid/
                    // outstanding), linking an existing invoice, and creating a
                    // new invoice for the project — consolidated into one key
                    // rather than four separate ones (view/link/create/summary
                    // are always granted together in practice). See
                    // Api\User\ProjectController's invoice-linking endpoints.
                    'canManageProjectInvoices',
                    // Lets a non-admin (e.g. a PM) create/link a project against
                    // an UNPAID invoice, bypassing the normal "must be paid"
                    // handoff safeguard — deliberately its own key (not folded
                    // into canManageProjectInvoices) since it bypasses a payment
                    // safeguard, a materially different risk tier. Company Admin
                    // never needs this — the admin guard already bypasses every
                    // sub-user permission check.
                    'canOverrideProjectCreationBeforePayment',
                    'canEditProjects', 'canAssignProjects',
                    // Assign/switch a project's Seller to any active Seller of
                    // the same company. Company Admin is always structurally
                    // allowed (the admin guard bypasses every permission check
                    // here, same as canCompleteProjects et al.) — this key only
                    // ever gates a PM-tier sub-user, and is NOT granted to any
                    // role by default (see RoleDefaultPermissions); Company
                    // Admin must explicitly grant it. See
                    // App\Services\ProjectSellerAssignmentService.
                    'canAssignProjectSeller',
                    'canViewTasks', 'canCreateTasks',
                    'canEditTasks', 'canAssignTasks', 'canViewTeamResources', 'canAssignTeamResources',
                    'canViewTimesheets', 'canApproveTimesheets', 'canViewProjectReports',
                    'canExportProjectReports', 'canViewTaskReports', 'canExportTaskReports',
                    'canViewProjectDocuments', 'canUploadProjectDocuments',
                    'canShareProjectDocuments',
                    'canCreateRevisions',
                    'canResolveRevisions', 'canUploadDeliverables', 'canDeliverDeliverables',
                    'canViewProductionReports',
                    'canUpdateProductionTasks',
                    'canViewDeliverables', 'canReviseDeliverables', 'canApproveDeliverables',
                    'canAddProductionNotes',
                    'canUploadProjectAttachments', 'canViewProjectAttachments',
                    'canDownloadProjectAttachments', 'canDeleteProjectAttachments',
                    'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                    'canDeleteTaskAttachments',
                    'canViewAllCompanyProjects',
                    // Seller-tier project/task permissions — deliberately narrower
                    // than the PM-level keys above (canViewProjects/canCreateTasks/
                    // canAssignTasks etc.), so a Seller can hand off and follow up
                    // on their own linked work without gaining general PM access.
                    // See Api\User\ProjectController/TaskController/ProjectCommentController.
                    'canViewLinkedProjects',
                    'canCreateLinkedProjectTask',
                    'canRequestPMAssignment',
                    'canViewLinkedProjectStatus',
                    'canAddClientFacingComment',
                    // Project lifecycle (Complete/Close/Reopen) — Company Admin is
                    // always structurally allowed; these gate a PM-tier sub-user.
                    // See App\Services\ProjectCompletionService.
                    'canCompleteProjects', 'canCloseProjects', 'canReopenProjects',
                    'canForceCloseProjects', 'canViewClosedProjects',
                    // Activate a draft project — the state a project is auto-created
                    // in when a client's payment starts it (see
                    // App\Services\PaymentProjectStartService). Holding this key is
                    // also what makes draft projects VISIBLE at all to a sub-user;
                    // without it they're filtered out of every list, since an
                    // unactivated draft is a name-only stub. Company Admin is always
                    // structurally allowed (the admin guard bypasses every permission
                    // check). NOT granted to any role by default — same convention as
                    // canAssignProjectSeller/canForceCloseProjects.
                    'canActivateProjects',
                    // Task status changes are a simple, unguarded free-jump for
                    // any allowed actor (Developer/Team Member on their own
                    // task, QA, PM, Company Admin) — see
                    // App\Services\TaskStatusService::canChangeTaskStatus().
                    // canOverrideTaskStatus is the one remaining manual escape
                    // hatch (a Company Admin grant of free status-change rights
                    // to a non-PM/QA user).
                    'canOverrideTaskStatus',
                    // Project-wise messenger: one conversation per project.
                    // Group/direct project chat actions were retired with the
                    // single-thread ProjectMessengerController flow.
                    'canViewProjectChat', 'canSendProjectChatMessage',
                    'canManageProjectChatParticipants',
                    'canAddSellerToProjectChat',
                    'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
                    'canDeleteAnyProjectChatMessage',
                ],
            ],

            // Not a purchasable module — a single company-wide capability
            // (whether this staff member can add other users) that used to be
            // duplicated inside every module above. Always available regardless
            // of which modules the company has purchased.
            'account' => [
                'name'        => 'Account',
                'permissions' => [
                    'canAddUsers',
                    // Settings Management — gives this staff member the whole
                    // Settings screen for ONE company: company profile/logo,
                    // invoice defaults, bank details, payment gateway accounts
                    // and the deal workflow. Scoped to the company it was
                    // granted for, like every other key here, so a
                    // multi-company user can hold it for Company A and not
                    // Company B. Enforced by Api\User\SettingsController.
                    //
                    // NOT granted by any role default, company_admin and
                    // admin included — see RoleDefaultPermissions::
                    // ALWAYS_EXPLICIT_PERMISSIONS for why it is excluded even
                    // from those two roles' "everything" computation.
                    'canManageSettings',
                    // Superseded by canManageSettings above, which is the key
                    // the real Settings screen checks. Kept because existing
                    // user_company_permissions rows reference them and
                    // dropping a catalog key makes those rows fail
                    // ModuleCatalog::isValidPermission() and silently vanish
                    // on the next permission save; nothing reads them.
                    'canViewPaymentGateways', 'canManagePaymentGateways', 'canViewGatewayTransactions',
                    // General Chat (direct/group messaging, not tied to any
                    // project/lead) — lives here rather than under a
                    // purchasable module since it's meant to be available
                    // across portals regardless of which modules a company
                    // bought. See Api\User\GeneralChatController.
                    'canUseGeneralChat',
                ],
            ],
        ];
    }

    public static function getPermissionsForModule(string $moduleKey): array
    {
        return self::all()[$moduleKey]['permissions'] ?? [];
    }

    public static function isValidPermission(string $moduleKey, string $permKey): bool
    {
        return in_array($permKey, self::getPermissionsForModule($moduleKey));
    }

    public static function isModuleAvailable(string $moduleKey, array $purchasedModules): bool
    {
        $catalog = self::all();
        if (!isset($catalog[$moduleKey]) || !in_array($moduleKey, $purchasedModules)) {
            return false;
        }
        $module = $catalog[$moduleKey];

        if (!empty($module['requires'])) {
            foreach ($module['requires'] as $req) {
                if (!in_array($req, $purchasedModules)) return false;
            }
        }

        if (!empty($module['requiresAny'])) {
            foreach ($module['requiresAny'] as $req) {
                if (in_array($req, $purchasedModules)) return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Map granular DB module keys → catalog module keys.
     * Matches the DB_TO_CATALOG mapping in the frontend moduleCatalog.ts.
     */
    public static function dbKeyToCatalog(string $dbKey): string
    {
        return match($dbKey) {
            'clients', 'client_portal'                                        => 'client',
            'invoices', 'payments', 'payment_details', 'invoice_reminders'    => 'invoice',
            'leads', 'projects_handoff', 'lead_transfer', 'reports_seller'    => 'sales',
            'employees', 'recruitment', 'attendance', 'leaves', 'payroll'     => 'hr',
            'finance_dashboard', 'finance_reports',
            'revenue_reports', 'payments_report'                              => 'finance',
            'projects', 'tasks', 'timesheets', 'revisions', 'deliverables',
            'team_resources', 'file_storage', 'production'                    => 'project_management',
            'compliance'                                                       => 'compliance',
            default                                                            => $dbKey,
        };
    }
}
