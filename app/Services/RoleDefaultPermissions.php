<?php

namespace App\Services;

// Role → default permission mapping for the Add/Edit User "select role, get
// default permissions" flow. Every key referenced here is a real, currently
// enforced ModuleCatalog permission — roles never grant a permission that
// has no backing feature. Some spec-requested labels (e.g. "Add Task
// Comments", "Mark Attendance") have no distinct permission key anywhere in
// the app today and are intentionally omitted rather than inventing one.
class RoleDefaultPermissions
{
    // catalogModuleKey => permission keys, per role. 'company_admin' and
    // 'viewer' are computed dynamically instead (see forRole()) since they
    // span every purchased module rather than a fixed list.
    // Each role's project_management list below is a union of whole
    // simplified-permission bundles (see
    // frontend/lib/simplifiedProjectPermissions.ts) — mirrors
    // frontend/lib/roleUtils.ts's ROLE_DEFAULT_PERMISSIONS exactly. Keep any
    // change here mirrored there too.
    // 'admin' is absent here on purpose: like 'company_admin' and 'viewer' it
    // is computed in forRole() (every permission of every purchased module)
    // rather than listed as a fixed set.
    private const MAP = [
        'project_manager' => [
            // pm_view + pm_manage_projects + pm_view_tasks + pm_manage_tasks + pm_edit_tasks + pm_manage_team +
            // pm_manage_production + pm_manage_deliverables + pm_manage_timesheets +
            // pm_view_reports + pm_manage_files + pm_manage_comments + pm_manage_chat
            // + every "advanced" (collapsed-by-default) bundle item —
            // 2026-08-13: explicitly granted per Company Admin request,
            // overriding the prior "NOT included by default" convention for
            // canAssignProjectSeller/canActivateProjects/canForceCloseProjects.
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                // canManageProjectInvoices deliberately excluded (reverted
                // 2026-08-19) — it is otherwise part of the pm_manage_projects
                // bundle, but Company Admin does not want PM to have
                // invoice-management ability by default; a stray backfill
                // migration had force-granted it to every PM regardless of
                // this list, see 2026_08_19_*_revoke_unintended_pm_invoice_permission.
                'canCreateProjects', 'canCreateProjectHandoff', 'canEditProjects', 'canCompleteProjects', 'canCloseProjects', 'canReopenProjects',
                // canEditTasks stays a PM default, but it is its OWN checkbox
                // now (pm_edit_tasks, split out of the Manage Tasks bundle
                // 2026-09-02) and is enforced permission-only server-side —
                // Api\User\TaskController::update()'s $canEdit no longer has
                // a PM-tier bypass, so a Company Admin revoking "Edit Tasks"
                // for a specific PM really does take task-field editing away.
                'canViewTasks', 'canCreateTasks', 'canCreateLinkedProjectTask', 'canEditTasks', 'canAssignTasks',
                'canOverrideTaskStatus',
                'canViewTeamResources', 'canAssignTeamResources', 'canRequestPMAssignment',
                'canViewDeliverables', 'canUploadDeliverables', 'canApproveDeliverables', 'canCreateRevisions', 'canResolveRevisions',
                'canViewTimesheets', 'canApproveTimesheets',
                'canViewProjectReports', 'canViewTaskReports',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments', 'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddClientFacingComment',
                // canManageProjectChatParticipants deliberately excluded — a
                // PM manages this project's PEOPLE via "Manage Team" instead
                // (assignTeam() already syncs each addition into chat, see
                // ProjectChatService::addTeamMember()); Participants here is
                // reserved for the Seller-invited PM flow / a company-wide
                // overseer, never the literal PM (see
                // Api\User\ProjectMessengerController::canManageParticipants()).
                'canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments', 'canDeleteAnyProjectChatMessage',
                // Advanced bundle additions.
                'canAssignProjectSeller', 'canActivateProjects', 'canForceCloseProjects',
                'canDeleteProjectAttachments', 'canDeleteTaskAttachments', 'canAddSellerToProjectChat',
                // canViewAllCompanyProjects deliberately excluded (reverted
                // 2026-08-15) — it granted implicit access to EVERY project's
                // chat company-wide (Api\User\ProjectMessengerController::
                // isPM()/resolveThreadForViewing()), which defeated the
                // Seller's own "Invite Project Manager into Project Chat"
                // feature entirely (nothing to invite into if the PM already
                // had unconditional access) and let a PM manage chat
                // Participants on projects they aren't actually assigned to.
                // A PM now only ever sees/manages projects they're literally
                // assigned to (project_manager_id or a team member row),
                // company-wide or Seller-invited access aside.
                
                'canViewClosedProjects',
                'canOverrideProjectCreationBeforePayment',
            ],
            // General Chat (direct/group messaging, not tied to a project) —
            // PM is one of the cross-department roles expected to use it.
            'account' => ['canUseGeneralChat'],
        ],
        // WORKER ROLES (production / developer / designer / team_member) —
        // 2026-09-04 request: these four work the tasks handed to them and
        // nothing more. canCreateTasks, canCreateLinkedProjectTask,
        // canEditTasks and canAssignTasks were all defaults here and are now
        // deliberately excluded: "a developer cannot assign or reassign a
        // task to another developer... only view the task and its detail,
        // cannot create either". canViewTasks stays, and progressing their
        // OWN task's status still works without any permission at all
        // (TaskStatusService::canChangeTaskStatus()'s is_assignee path), so
        // they can still move their work to Done. Reassignment additionally
        // needed a hard role check to really close — see
        // Api\User\TaskController::NON_REASSIGNING_ROLES, since update()'s
        // $isOwnTask/$isTeamMember paths widen it past any permission.
        // PM and QA deliberately keep the full task bundle.
        // pm_view + pm_manage_production + pm_manage_deliverables + pm_manage_files + pm_manage_comments + pm_manage_chat
        'production' => [ // "Production User"
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canViewTasks',
                'canViewDeliverables', 'canUploadDeliverables', 'canApproveDeliverables', 'canCreateRevisions', 'canResolveRevisions',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments', 'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
            ],
        ],
        // See the worker-roles note on 'production' above.
        'developer' => [
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canViewTasks',
                'canViewDeliverables', 'canUploadDeliverables', 'canApproveDeliverables', 'canCreateRevisions', 'canResolveRevisions',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments', 'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
            ],
        ],
        // See the worker-roles note on 'production' above.
        'designer' => [
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canViewTasks',
                'canViewDeliverables', 'canUploadDeliverables', 'canApproveDeliverables', 'canCreateRevisions', 'canResolveRevisions',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments', 'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
            ],
        ],
        // pm_view + pm_view_tasks + pm_manage_tasks + pm_edit_tasks + pm_manage_deliverables + pm_manage_files + pm_manage_comments + pm_manage_chat (no Production)
        // + every "advanced" (collapsed-by-default) bundle item — 2026-08-13:
        // same explicit grant as project_manager above.
        'qa' => [
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canViewTasks', 'canCreateTasks', 'canCreateLinkedProjectTask', 'canEditTasks', 'canAssignTasks',
                'canViewDeliverables', 'canUploadDeliverables', 'canApproveDeliverables', 'canCreateRevisions', 'canResolveRevisions',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments', 'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
                // Advanced bundle additions.
                'canAssignProjectSeller', 'canActivateProjects', 'canForceCloseProjects',
                'canDeleteProjectAttachments', 'canDeleteTaskAttachments', 'canAddSellerToProjectChat',
                'canViewAllCompanyProjects', 'canViewClosedProjects', 'canManageProjectChatParticipants',
                'canDeleteAnyProjectChatMessage', 'canOverrideProjectCreationBeforePayment',
                // Team/Resources — same oversight the 2026-08-14 Lead Manager
                // backfill fixed: QA already gets canAssignProjectSeller and
                // canViewAllCompanyProjects above (more sensitive than
                // Team/Resources), so leaving Team/Resources itself out was
                // an inconsistent omission, not a deliberate restriction.
                'canViewTeamResources', 'canAssignTeamResources',
            ],
        ],
        // pm_view + pm_manage_files + pm_manage_comments + pm_manage_chat (no Production, no Deliverables/QA)
        // See the worker-roles note on 'production' above.
        'team_member' => [
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canViewTasks',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments', 'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
            ],
        ],
        // Sellers only ever see/act on projects they're linked to, and only
        // chat once someone adds them to a thread — no general Task/
        // Production/Deliverables management, no self-initiated direct chat,
        // no full project attachment access (that flat, untiered file list
        // would otherwise expose internal project files to a Seller).
        'seller' => [
            'sales' => [
                // canTransferLeads is deliberately NOT granted — a Seller
                // works their own leads only; transferring a lead to another
                // Seller is a Lead Manager/Company Admin action.
                // canUpdateSalesTargets is deliberately NOT granted — a
                // Seller may only VIEW the target set for them
                // (canViewSalesTargets); creating/editing it is Company
                // Admin/Lead Manager territory (see canManageSalesTargets on
                // the 'lead_manager' entry below).
                'canViewSalesDashboard', 'canViewLeads', 'canCreateLeads', 'canEditLeads', 'canDeleteLeads',
                'canManagePipeline', 'canAddLeadNotes', 'canViewSalesTargets', 'canViewSalesReports',
                'canUseSalesChat',
                // Basic client access is bundled with Sales (see
                // ModuleCatalog) so a Seller can pick a client when invoicing
                // from Sales/Client detail pages without buying the full
                // Client module — without these a Seller's invoice-create
                // "Select Client" list is always empty even after Company
                // Admin adds a client, which looks like a bug but was a
                // missing default grant.
                'canViewClients', 'canCreateClients', 'canEditClients',
            ],
            // canEnableClientPortal/canDisableClientPortal validate only
            // against the 'client' catalog module (ModuleCatalog.php), not
            // 'sales' — they used to live inside the 'sales' array above,
            // which meant RoleDefaultPermissions::forRole()'s own per-bucket
            // isValidPermission() check silently dropped both keys from
            // every "auto-select Seller defaults" computation (verified via
            // tinker). Moved to their own bucket so a Seller actually gets
            // to set up/revoke their own client's portal login by default,
            // once the Client module is active for the company.
            // canView/Create/EditClients are repeated here (already granted
            // above under 'sales') purely so the "Clients" tab's own
            // checkboxes show checked too — Add/Edit User's per-module UI
            // only reads a key under the module bucket it's rendered in.
            'client' => [
                'canEnableClientPortal', 'canDisableClientPortal',
                'canViewClients', 'canCreateClients', 'canEditClients',
                'canResetClientPassword', 'canViewClientPayments', 'canViewClientInvoices',
                'canManageClientDocuments', 'canViewClientDocuments',
            ],
            'invoice' => [
                'canCreateInvoices', 'canSendInvoices', 'canViewInvoices', 'canEditInvoices',
                'canDownloadOrExportInvoices', 'canViewPayments', 'canRecordPayments',
                'canSendPaymentReminders', 'canManageBillingClients', 'canViewInvoiceReports',
            ],
            // Finance Area — a Seller is one of the two roles with Finance
            // access by default (Company Admin being the other), per the
            // Finance spec. Only granted at all if the company has bought the
            // Finance module: forRole() filters by $purchasedCatalogModules,
            // and 'finance' additionally requires 'invoice'.
            //
            // Scope is READ + RECORD, deliberately not everything:
            //   • canReconcilePayments / canReconcilePaymentDetails are NOT
            //     here. Reconciliation is the sign-off that says the money
            //     really arrived, and a Seller signing off payments on their
            //     own deals is the separation-of-duties problem the whole
            //     feature exists to solve. Company Admin grants it per person.
            //   • No canExport* keys. Export hands someone the company's whole
            //     financial history as a file; that is an explicit grant, the
            //     same posture as canManageSettings.
            // Everything here a Seller could already do through the Invoice
            // module (they hold canViewInvoices/canCreateInvoices/
            // canRecordPayments etc. above) — this only opens the Finance
            // Area's own screens onto the same data, scoped to the same
            // invoices they can already see.
            'finance' => [
                'canViewFinanceDashboard', 'canViewRevenueDashboard',
                'canViewFinanceInvoices', 'canCreateFinanceInvoices', 'canUpdateFinanceInvoices',
                'canViewPayments', 'canRecordPayments', 'canViewPaymentDetails',
                'canCreateInvoiceReminders', 'canSendInvoiceReminders', 'canTrackInvoiceReminders',
                'canViewFinanceReports', 'canViewRevenueReports', 'canViewPaymentReports',
            ],
            // Seller can only ever share a comment/chat thread with Company
            // Admin or this project's PM — never the wider team. Project
            // chat is now the single project conversation, scoped by
            // participant rows.
            // canCreateLinkedProjectTask is deliberately NOT granted — a
            // Seller never CREATES a task (Api\User\TaskController::store()
            // still hard-blocks role_type='seller' outright, regardless of
            // this or any other permission a Company Admin might grant).
            // 2026-09-04: a Seller CAN now be assigned a task by a PM, and
            // reaches exactly the tasks assigned to them —
            // index()/indexAll()/myTasks()/activity()/update() each carry a
            // permission-free, hard-scoped Seller branch (own assigned tasks
            // only, status/comments only, never reassignment). That access is
            // role-derived on purpose, so no task permission needs granting
            // here for it to work.
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canCreateProjectHandoff',
                'canViewTeamResources', 'canAssignTeamResources', 'canRequestPMAssignment',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage',
                'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
                // canManageProjectInvoices — explicitly re-included per
                // 2026-08-13 request (supersedes the prior 2026-08-11
                // exclusion policy). Functional, not cosmetic: unlocks
                // ProjectController::linkInvoice()/unlinkInvoice()/
                // createInvoice() and the project billing summary for a
                // Seller's own linked/handed-off project.
                'canManageProjectInvoices',
                // canEditProjects/canCompleteProjects/canCloseProjects/
                // canReopenProjects ARE functional for a Seller —
                // visibleProjects()/ProjectController scope includes
                // seller_id match, so these apply to a Seller's own linked/
                // handed-off project.
                'canEditProjects', 'canCompleteProjects', 'canCloseProjects', 'canReopenProjects',
                // canCreateProjects — functional per 2026-08-15 request: a
                // Seller holding this now gets the same unrestricted
                // "+ New Project" path as PM/Manager tiers (store() no longer
                // hard-blocks role_type='seller' here). canCreateProjectHandoff
                // above remains the lead/invoice-gated alternative for a
                // Seller who should only create a project as a deal handoff.
                'canCreateProjects',
                // canActivateProjects — functional, not cosmetic: a draft
                // project auto-created from a client's payment
                // (PaymentProjectStartService) is most often the Seller's OWN
                // handed-off deal, and visibleProjects()'s seller_id match
                // already lets them see it — without this permission they
                // could see the draft but never activate it themselves,
                // needing Company Admin every time. Granted by default
                // alongside Admin per 2026-08-13 request.
                'canActivateProjects',
                // "Manage Project Files" bundle. canUploadProjectAttachments IS
                // functional (ProjectAttachmentController::visibleProject() now
                // includes a seller_id match). canViewProjectAttachments/
                // canDownloadProjectAttachments are cosmetic-only — index()/
                // download() branch to the seller-only "visible to client"
                // subset unconditionally, before this permission is ever
                // checked. canUploadTaskAttachments/canViewTaskAttachments/
                // canDownloadTaskAttachments became functional on 2026-09-04
                // (a Seller can now hold an assigned task — see the note
                // above): they gate the Attachments panel on that task's
                // detail page. A Seller created BEFORE that date had these
                // stripped by 2026_08_05_160000_revoke_all_task_permissions_
                // from_sellers, so they see the task but not its files until a
                // Company Admin re-grants them.
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments',
                'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                // canAddSellerToProjectChat is functional (gates
                // ProjectMessengerController::addParticipants() adding
                // another Seller). canManageProjectChatParticipants is
                // cosmetic-only for this role — canManageParticipants()
                // hard-blocks role_type='seller' before this permission is
                // ever checked; kept here only so the checkbox shows checked
                // per explicit request.
                'canAddSellerToProjectChat',
                'canManageProjectChatParticipants',
            ],
            // General Chat — Seller is one of the spec's named General Chat roles.
            'account' => ['canUseGeneralChat'],
        ],
        // Lead Manager — manages leads and assigns/transfers them to
        // Sellers, company-wide (canViewAllCompanyLeads), but never gets
        // full Company Admin access: no user/settings/payment-gateway
        // management, unless a Company Admin manually grants those on top
        // (see Api\User\LeadController::assignableSeller() for the backend-
        // enforced "same company, active, role_type=seller" rule on WHO a
        // lead can be assigned/transferred to — independent of this
        // permission set). canManagePipeline already covers marking a lead
        // Won/Lost (LeadController::updateStatus()), and
        // canViewSalesDashboard + canViewAllCompanyLeads together already
        // surface the per-seller performance breakdown
        // (Api\User\SalesDashboardController::index()'s 'sellers' block) —
        // neither needs its own dedicated permission key.
        //
        // 2026-08-13: given the same Client/Sales/Invoice/Project Management
        // bundle as Seller (see the 'seller' entry above), PLUS the extra
        // company-wide/lead-ownership keys (canViewAllCompanyLeads,
        // canAssignLeadOwner, canTransferLeads, canDeleteLeads) a Seller
        // deliberately doesn't get — Lead Manager sits a tier above Seller,
        // not beside it. This supersedes the older "no lead deletion, no
        // invoice creation/sending" restriction.
        'lead_manager' => [
            'sales' => [
                'canViewSalesDashboard', 'canViewLeads', 'canViewAllCompanyLeads',
                'canCreateLeads', 'canEditLeads', 'canDeleteLeads', 'canAssignLeadOwner', 'canTransferLeads',
                'canManagePipeline', 'canAddLeadNotes',
                // canUpdateSalesTargets ("set my OWN target") is deliberately
                // NOT granted: a Lead Manager's own target is set for them by
                // Company Admin and is view-only to them — enforced by role in
                // Api\User\SalesTargetController::selfEditBlocked(), so this
                // stays closed even if the permission is re-granted by hand.
                // canManageSalesTargets is the separate power to create/update
                // every SELLER's target in their company; Sellers themselves
                // hold only canViewSalesTargets (see the 'seller' entry above).
                'canViewSalesTargets', 'canManageSalesTargets', 'canViewSalesReports',
                'canViewClients', 'canCreateClients', 'canEditClients',
                // A Lead Manager can create leads/invoices themselves (see
                // above/'invoice' bucket below) — without this they had no
                // way to reach the "Chat with Seller" conversation on one of
                // their own, even though App\Services\InvoiceChatContext
                // already resolves them as the seller to notify. Scoped to
                // their own leads/clients/invoices only — see
                // Api\User\SalesChatController::canActAsChatOwner().
                'canUseSalesChat',
            ],
            'client' => [
                'canEnableClientPortal', 'canDisableClientPortal',
                'canViewClients', 'canCreateClients', 'canEditClients',
                // Mirrors canViewAllCompanyLeads above — a Lead Manager
                // oversees the whole team's pipeline, so a client a Seller
                // created (account_manager = that Seller, not this Lead
                // Manager) must be visible too, not just their own/unowned
                // ones (see Api\User\ClientController::visibleClients()).
                'canViewAllCompanyClients',
                // Same reasoning as canTransferLeads above — a Lead Manager
                // redistributes ownership across the team; a Seller does not
                // get this by default (see Api\User\ClientController::transfer()).
                'canTransferClients',
                'canResetClientPassword', 'canViewClientPayments', 'canViewClientInvoices',
                'canManageClientDocuments', 'canViewClientDocuments',
            ],
            // Only actually granted if the company has purchased the
            // invoice module (forRole() below filters by
            // $purchasedCatalogModules).
            'invoice' => [
                'canCreateInvoices', 'canSendInvoices', 'canViewInvoices', 'canEditInvoices',
                'canDownloadOrExportInvoices', 'canViewPayments', 'canRecordPayments',
                'canSendPaymentReminders', 'canManageBillingClients', 'canViewInvoiceReports',
                // Mirrors canViewAllCompanyLeads/Clients above — a Lead
                // Manager oversees the whole team's invoicing too, not just
                // invoices they personally created (see
                // Api\User\InvoiceController::visibleInvoices()).
                'canViewAllCompanyInvoices',
            ],
            // Same Seller-tier project bundle as the 'seller' entry above —
            // see its comments for which of these are functional vs.
            // cosmetic-only. Several (canEditProjects/canCompleteProjects/
            // etc.) key off a project's seller_id match, which Lead Manager
            // won't normally have, so those are cosmetic here unless this
            // Lead Manager is also set as a project's seller/PM.
            'project_management' => [
                'canViewProjectDashboard', 'canViewProjects', 'canViewLinkedProjects',
                'canCreateProjectHandoff',
                // canViewTeamResources/canAssignTeamResources — 2026-08-14
                // request: Lead Manager needs the "assign project"
                // (Team/Resources page's Add Team Member) option, same as
                // Seller already has above. Without these, canRequestPMAssignment
                // only ever let them name a PM at handoff-creation time —
                // nothing let them staff/reassign a project's team afterward.
                'canViewTeamResources', 'canAssignTeamResources', 'canRequestPMAssignment',
                'canAddClientFacingComment',
                'canViewProjectChat', 'canSendProjectChatMessage',
                'canUploadProjectChatAttachment', 'canViewProjectChatAttachments',
                'canManageProjectInvoices',
                'canEditProjects', 'canCompleteProjects', 'canCloseProjects', 'canReopenProjects',
                'canCreateProjects',
                // Mirrors the 'seller' entry's own canActivateProjects grant —
                // same "cosmetic unless also this project's seller/PM" caveat
                // as the block comment above.
                'canActivateProjects',
                'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments',
                'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
                'canAddSellerToProjectChat',
                'canManageProjectChatParticipants',
                // Company-wide project oversight — 2026-08-14 request: Lead
                // Manager already gets canViewAllCompanyLeads (company-wide,
                // not scoped to specific sellers — there's no "which sellers
                // does this Lead Manager manage" relationship in the data
                // model), so mirroring that with company-wide PROJECT
                // visibility is what actually lets them see and manage every
                // Seller's assigned/created projects, not just their own.
                // Same grant project_manager/qa already got per the
                // 2026-08-13 entries above. Doesn't expose budget — index()
                // hides that field unconditionally regardless of this
                // permission.
                'canViewAllCompanyProjects', 'canViewClosedProjects',
            ],
            'account' => ['canUseGeneralChat'],
        ],
        'invoice_user' => [
            'invoice' => [
                'canViewInvoices', 'canCreateInvoices', 'canEditInvoices', 'canSendInvoices',
                'canViewPayments', 'canSendPaymentReminders', 'canSelectInvoiceGateway',
            ],
        ],
        'hr' => [ // "HR User"
            // Extended 2026-09-21 alongside ModuleCatalog's new HR keys —
            // an HR User gets the working set for every HR sub-module by
            // default (view + the module's own day-to-day actions), same
            // breadth as the pre-existing keys already granted here.
            // canApplyLeave joined this list the same day, once
            // Api\User\LeaveController@store actually started checking it —
            // canViewHRNotifications stays OUT: no dedicated HR-notifications
            // gate exists anywhere (HR events surface through the same
            // general notification feed every module uses), so defaulting it
            // would violate this file's own rule that every key here is
            // currently enforced somewhere.
            // canCreateEmployees/canEditEmployees/canApproveLeave removed
            // from defaults on request — still valid, grantable permissions
            // (see ModuleCatalog.php), just no longer auto-applied to a new
            // HR user. Everything else here is unchanged.
            'hr' => [
                'canViewHRDashboard', 'canViewEmployees',
                'canManageEmployeeDocuments',
                'canViewAttendance', 'canUpdateAttendance',
                'canViewLeave', 'canApplyLeave',
                'canViewPayroll', 'canUpdatePayroll', 'canProcessPayroll',
                'canViewRecruitment', 'canCreateRecruitment', 'canUpdateRecruitment', 'canManageRecruitment',
                'canViewHRReports', 'canExportHRReports',
            ],
            'account' => ['canUseGeneralChat'],
        ],
        // "Finance User" — the role that exists FOR the Finance Area, so it
        // gets the whole module including the two things a Seller
        // deliberately does not (reconciliation and exports). Reconciliation
        // in particular was already unreachable for this role before now:
        // Api\User\PaymentController::confirm()/reject() has gated on
        // canReconcilePayments since it was written, expressly so a "Finance
        // User: verify offline payments" flow would exist, but the key was
        // never in these defaults — so no Finance User could ever verify a
        // payment without a Company Admin ticking it by hand.
        'finance' => [
            'finance' => [
                'canViewFinanceDashboard', 'canViewRevenueDashboard',
                'canViewFinanceInvoices', 'canCreateFinanceInvoices',
                'canUpdateFinanceInvoices', 'canExportFinanceInvoices',
                'canViewPayments', 'canRecordPayments', 'canReconcilePayments',
                'canViewPaymentDetails', 'canReconcilePaymentDetails', 'canExportPaymentDetails',
                // The whole company's money, not just records this person is
                // tied to — see the key's note in ModuleCatalog. Deliberately
                // absent from the Seller defaults above.
                'canViewAllCompanyFinance',
                'canCreateInvoiceReminders', 'canSendInvoiceReminders', 'canTrackInvoiceReminders',
                'canViewFinanceReports', 'canExportFinanceReports',
                'canViewRevenueReports', 'canExportRevenueReports',
                'canViewPaymentReports', 'canExportPaymentReports',
            ],
            'account' => ['canUseGeneralChat'],
        ],
        'compliance' => [ // "Compliance User"
            // Simplified 5-key model (2026-09-03) — see ModuleCatalog.php's
            // compliance entry for what each key does. A new Compliance User
            // gets view + do-the-work + download + full visibility of their
            // OWN company's compliance cases by default (2026-09-08 —
            // canViewAllCompanyCompliance only bypasses the tied-to-case
            // restriction WITHIN activeCompanyId(); it does not span other
            // companies, so it belongs in the default set: "a compliance
            // user should see all of their company's compliance work", not
            // just cases they happen to be officer/PM/seller/team-member on).
            // canAssignComplianceUser stays an explicit Company Admin grant.
            'compliance' => [
                'canViewCompliance', 'canChangeComplianceStatus', 'canDownloadComplianceData',
                'canViewAllCompanyCompliance',
            ],
            'account' => ['canUseGeneralChat'],
        ],
    ];

    /**
     * Permissions that are NEVER part of any role's defaults — not even
     * company_admin's or admin's, whose defaults are otherwise "every
     * permission of every purchased module" (see forRole()). A Company Admin
     * has to tick these by hand, per company, for each person who should hold
     * them.
     *
     * catalogModuleKey => permission keys.
     *
     * 'account' => canManageSettings (2026-09-11, explicit request): Settings
     * hands over the company's invoicing identity, bank details and live
     * payment gateway credentials, so "off unless someone deliberately turned
     * it on" is the whole point of the permission — a default-on grant to the
     * Admin deputy role would have meant every new Admin silently got it.
     * The Company Admin's OWN access is unaffected: their login is a
     * CompanyAdmin row on the `admin` guard, which bypasses permission checks
     * entirely and reaches Settings through Api\Admin\SettingsController.
     *
     * Keep mirrored with frontend/lib/roleUtils.ts's
     * ALWAYS_EXPLICIT_PERMISSIONS, which does the same filtering so the
     * Add/Edit User form never renders a box ticked that the backend is about
     * to leave off.
     */
    public const ALWAYS_EXPLICIT_PERMISSIONS = [
        'account' => ['canManageSettings'],
    ];

    /**
     * @param string $role One of USER_ROLES.
     * @param string[] $purchasedCatalogModules Catalog module keys the company has active
     *                 (e.g. ['sales', 'invoice', 'project_management'] — already
     *                 resolved via ModuleCatalog::dbKeyToCatalog(), not raw DB keys).
     * @return array<string, string[]> catalogModuleKey => permission keys
     */
    public static function forRole(string $role, array $purchasedCatalogModules): array
    {
        // 'admin' starts with everything, same as company_admin — a new Admin
        // user is provisioned with the full permission set for whatever the
        // company has purchased (plus the always-available 'account' module),
        // and the Company Admin takes away whatever that particular person
        // shouldn't have. This is a DEFAULT for newly created users (and for
        // an existing user whose role is changed, which re-applies defaults) —
        // it never rewrites permissions already granted to someone.
        if ($role === 'company_admin' || $role === 'admin') {
            return self::stripAlwaysExplicit(
                self::allPermissionsForModules(array_merge($purchasedCatalogModules, ['account']))
            );
        }

        if ($role === 'viewer') {
            return self::stripAlwaysExplicit(self::viewOnlyForModules($purchasedCatalogModules));
        }

        $raw = self::MAP[$role] ?? [];
        $filtered = [];
        // 'account' (e.g. canUseGeneralChat) is never a purchased DB module —
        // always considered available here too, same reasoning as the
        // company_admin branch above.
        $availableModules = array_merge($purchasedCatalogModules, ['account']);

        foreach ($raw as $moduleKey => $permKeys) {
            if (!in_array($moduleKey, $availableModules, true)) continue;
            // Defensive: only ever return keys that really exist in the catalog today.
            $valid = array_values(array_filter(
                $permKeys,
                fn ($p) => ModuleCatalog::isValidPermission($moduleKey, $p)
            ));
            if (!empty($valid)) $filtered[$moduleKey] = $valid;
        }

        return self::stripAlwaysExplicit($filtered);
    }

    /**
     * The combined defaults for a user holding SEVERAL roles in one company.
     *
     * A plain union, and that is the whole contract: holding a second role can
     * only ever add grants. Nothing here subtracts, so no role can override or
     * take away what another role gave — a Seller who is also a Project Manager
     * gets both sets, not the stronger one and not the intersection.
     *
     * This mirrors how the runtime gate already behaves. user_company_permissions
     * rows are grant-only (there is no deny row anywhere in the schema) and
     * every can() check is an exists(), so unioning at seed time is simply the
     * same rule applied one step earlier.
     *
     * Order of $roles does not affect the result. Duplicates and unknown roles
     * are harmless — forRole() returns [] for anything it does not know.
     *
     * @param  array<int, string> $roles
     * @param  string[]           $purchasedCatalogModules
     * @return array<string, string[]> catalogModuleKey => permission keys
     */
    public static function forRoles(array $roles, array $purchasedCatalogModules): array
    {
        $merged = [];

        foreach (array_unique(array_filter($roles)) as $role) {
            foreach (self::forRole($role, $purchasedCatalogModules) as $moduleKey => $permKeys) {
                $merged[$moduleKey] = array_merge($merged[$moduleKey] ?? [], $permKeys);
            }
        }

        // stripAlwaysExplicit() is NOT re-applied here: forRole() already ran it
        // on every set being merged, and a union of filtered sets is filtered.
        return array_map(
            fn (array $keys) => array_values(array_unique($keys)),
            $merged
        );
    }

    /**
     * Drops every ALWAYS_EXPLICIT_PERMISSIONS key from a computed default set.
     *
     * Applied to all three branches of forRole() rather than only the
     * "everything" one: the MAP branch is hand-written and so already omits
     * them, but filtering there too means adding a key to
     * ALWAYS_EXPLICIT_PERMISSIONS is enough on its own to keep it out of
     * every role, present and future, without also auditing 14 role lists.
     */
    private static function stripAlwaysExplicit(array $permissions): array
    {
        foreach (self::ALWAYS_EXPLICIT_PERMISSIONS as $moduleKey => $excludedKeys) {
            if (!isset($permissions[$moduleKey])) {
                continue;
            }

            $kept = array_values(array_diff($permissions[$moduleKey], $excludedKeys));

            if ($kept === []) {
                unset($permissions[$moduleKey]);
                continue;
            }

            $permissions[$moduleKey] = $kept;
        }

        return $permissions;
    }

    private static function allPermissionsForModules(array $moduleKeys): array
    {
        $catalog = ModuleCatalog::all();
        $out = [];
        foreach ($moduleKeys as $key) {
            if (isset($catalog[$key])) $out[$key] = $catalog[$key]['permissions'];
        }
        return $out;
    }

    private static function viewOnlyForModules(array $moduleKeys): array
    {
        $catalog = ModuleCatalog::all();
        $out = [];
        foreach ($moduleKeys as $key) {
            if (!isset($catalog[$key])) continue;
            $viewKeys = array_values(array_filter(
                $catalog[$key]['permissions'],
                fn ($p) => str_starts_with($p, 'canView')
            ));
            if (!empty($viewKeys)) $out[$key] = $viewKeys;
        }
        return $out;
    }

    // The 14 roles the Add/Edit User "Select Role" dropdown offers, in
    // display order. Legacy role_type values (invoice_admin, invoice_manager,
    // invoice_creator, invoice_viewer, payment_manager) still validate
    // (existing users keep them) but are no longer offered as new picks.
    // Roles only a Company Admin may hand out. Filtered out of the list the
    // delegated User Manager's screens are served
    // (Api\User\UserManagementController::roleOptions()) and absent from that
    // controller's VALID_ROLES, so a manager can neither see nor send one.
    public const COMPANY_ADMIN_ONLY_ROLES = ['company_admin', 'admin'];

    public static function roleOptions(): array
    {
        return [
            ['value' => 'company_admin',    'label' => 'Company Admin'],
            // Brand keeper: maintains the trading names the company bills
            // under, and nothing else by default (see MAP['admin']).
            ['value' => 'admin',            'label' => 'Admin'],
            ['value' => 'project_manager',  'label' => 'Project Manager'],
            ['value' => 'production',       'label' => 'Production User'],
            ['value' => 'developer',        'label' => 'Developer'],
            ['value' => 'designer',         'label' => 'Designer'],
            ['value' => 'qa',               'label' => 'QA'],
            ['value' => 'team_member',      'label' => 'Team Member'],
            ['value' => 'seller',           'label' => 'Seller'],
            ['value' => 'lead_manager',     'label' => 'Lead Manager'],
            ['value' => 'invoice_user',     'label' => 'Invoice User'],
            ['value' => 'hr',               'label' => 'HR User'],
            ['value' => 'finance',          'label' => 'Finance User'],
            ['value' => 'compliance',       'label' => 'Compliance User'],
            ['value' => 'viewer',           'label' => 'Viewer'],
        ];
    }
}
