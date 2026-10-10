<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Mail\ProjectDeliveredMail;
use App\Models\CompanyUserAssignment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectDeliverySubmission;
use App\Models\ProjectFolder;
use App\Models\ProjectTeamMember;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentMailService;
use App\Services\AttachmentStorageService;
use App\Services\EventMailService;
use App\Services\ProjectChatService;
use App\Services\ProjectCompletionService;
use App\Services\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    use ScopesToActiveCompany;

    // System subfolders created under every project on creation.
    private const SYSTEM_FOLDERS = [
        'documents', 'tasks', 'production', 'compliance', 'invoices',
        'client-files', 'internal-notes', 'deliverables', 'revisions', 'timesheets',
    ];

    // Mirrors Api\User\ProjectController's own DELIVERY_MIMES/DELIVERY_MAX_KB
    // — same allowed file types/size for the final package, whichever side
    // uploads it.
    private const DELIVERY_MIMES = 'zip,pdf,doc,docx,xls,xlsx,png,jpg,jpeg';
    private const DELIVERY_MAX_KB = 51200;

    private function admin()   { return auth('admin')->user(); }
    private function adminName(): string { return $this->admin()->name ?? 'Admin'; }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // Active company-membership user ids for ONE specific company — used to
    // validate project_manager_id/team member ids belong to the project's own
    // company, not just "any company this admin owns" (companyIds() is too
    // broad for that check when an admin owns multiple companies).
    private function companyUserIds(int $companyId): array
    {
        return CompanyUserAssignment::where('company_id', $companyId)
            ->where('status', 'active')
            ->pluck('user_id')
            ->toArray();
    }

    // GET /admin/companies/{companyId}/project-users — assignable users for
    // the Project create/edit/team screens, grouped by role_type and scoped
    // to ONE company (not this admin's whole multi-company companyIds()).
    public function projectUsers(int $companyId): JsonResponse
    {
        if (!in_array($companyId, $this->companyIds(), true)) {
            return ApiResponse::error('Company not found', 404);
        }

        $users = User::whereIn('id', $this->companyUserIds($companyId))
            ->where('is_active', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role_type']);

        $toOption = fn ($u) => [
            'user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role_type,
        ];
        $group = fn (array $roles) => $users->whereIn('role_type', $roles)->values()->map($toOption);

        // Every internal (non-Seller/Client/Invoice/HR/Finance/Compliance) role
        // that can meaningfully be added to a project team.
        $internalRoles = ['project_manager', 'production', 'developer', 'designer', 'qa', 'team_member'];

        // Sellers can belong to more than one company. Include both the
        // legacy primary users.company_id and active CompanyUserAssignment
        // memberships so multi-company sellers appear for their selected
        // project company.
        // Users tied to this company via primary company_id OR an active
        // CompanyUserAssignment — company_user_assignments isn't reliably
        // populated for every user of a given role (verified against real
        // data), so a role scoped ONLY through companyUserIds()/$users above
        // (assignment-row-required) can miss real, active users of that role.
        // holdingRole(), not ->where('role_type', $role): a user given Seller as
        // a SECOND role keeps role_type='project_manager' (or whatever their
        // primary is), so the old test dropped them from the Assign/Switch
        // Seller dropdown even though they hold the role in this company. See
        // User::scopeHoldingRole().
        $byCompanyRole = fn (string $role) => User::where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('companyAssignments', fn ($a) => $a
                        ->where('company_id', $companyId)
                        ->where('status', 'active'));
            })
            ->where('is_active', true)
            ->where('status', 'active')
            ->holdingRole($companyId, $role)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role_type'])
            ->map($toOption);

        return ApiResponse::success([
            'project_managers'     => $group(['project_manager']),
            'production_users'     => $group(['production']),
            'developers'           => $group(['developer']),
            'designers'            => $group(['designer']),
            'qa_users'             => $group(['qa']),
            'team_members'         => $group($internalRoles),
            // Compliance Officer dropdown (Admin > Compliance > project case)
            // — scoped to role_type='compliance' only, same company-scoping
            // as 'sellers' below (not companyUserIds()-only, which requires
            // an assignment row every Compliance User may not have).
            // Replaces the old 'all_assignable_users' key, which wrongly let
            // the dropdown offer every internal delivery-team role too.
            'compliance_officers'  => $byCompanyRole('compliance'),
            // Active Sellers of this company only — deliberately excluded
            // from all_assignable_users/internalRoles above (a Seller is
            // never added to a project's internal team). Backs the "Assign
            // Seller" dropdown on the project detail page.
            'sellers'              => $byCompanyRole('seller'),
        ]);
    }

    private function sellerAssignmentService(): \App\Services\ProjectSellerAssignmentService
    {
        return app(\App\Services\ProjectSellerAssignmentService::class);
    }

    // PATCH /admin/projects/{id}/seller — assign or switch this project's
    // Seller to any active Seller of the same company. Company Admin is
    // always structurally allowed, mirroring every other action in this
    // guard (no permission check here).
    public function assignSeller(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'seller_id' => ['required', 'integer'],
            'reason'    => ['nullable', 'string', 'max:1000'],
        ]);

        $seller = $this->sellerAssignmentService()->assignableSeller($project->company_id, (int) $validated['seller_id']);
        if (!$seller) {
            return ApiResponse::error('Selected seller does not belong to this company.', 422);
        }

        $result = $this->sellerAssignmentService()->assign(
            $project, $seller, $validated['reason'] ?? null, null, $this->admin()->id, $this->adminName()
        );

        return ApiResponse::success(
            $project->fresh(['seller:id,name,email']),
            $result['is_switch'] ? 'Seller switched' : 'Seller assigned'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        // lead:id,name / invoice:id,customer_name — a guest project (no
        // client_id, e.g. a one-off invoice with only customer_name, or a
        // Lead never converted to a Client) has no `client` relation at all,
        // so the listing showed a blank name for it. Frontend falls back
        // client?.name -> invoice?.customer_name -> lead?.name.
        $q = Project::whereIn('company_id', $companyIds)
            ->with(['company:id,name', 'client:id,name,email,portal_access,user_id', 'lead:id,name', 'invoice:id,customer_name', 'projectManager:id,name,role_type', 'seller:id,name,role_type', 'teamMembers.user:id,name,role_type', 'createdBy:id,name', 'createdByAdmin:id,name']);

        // Company-Wise Dashboard Filtering — defaults to the active company
        // (matches ClientController::index()'s identical default), narrowed
        // further by an explicit ?company_id= override when given (still
        // validated by the whereIn above, so a foreign id yields zero rows
        // rather than leaking another tenant's data).
        if ($request->filled('company_id')) {
            $q->where('company_id', $request->company_id);
        } else {
            $q->whereIn('company_id', $this->activeCompanyIds());
        }
        if ($request->filled('status'))     $q->where('status', $request->status);
        if ($request->filled('priority'))   $q->where('priority', $request->priority);
        if ($request->filled('client_id'))  $q->where('client_id', $request->client_id);
        if ($request->filled('search'))     $q->where('name', 'like', '%' . $request->search . '%');

        $projects = $q->orderByDesc('created_at')->get();

        $taskStats = Task::whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as done')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $projects = $projects->map(function ($p) use ($taskStats) {
            $stats = $taskStats[$p->id] ?? null;
            // A completed/closed project reads 100% regardless of its task
            // ratio — otherwise one with zero tasks (or any tasks still open
            // at completion time) shows 0%/partial forever despite the work
            // being done.
            $p->progress  = in_array($p->status, ['completed', 'closed'], true)
                ? 100
                : ($stats && $stats->total > 0 ? round(($stats->done / $stats->total) * 100) : 0);
            $p->is_overdue = $p->deadline && $p->deadline->isPast() && $p->status !== 'completed';
            return $p;
        });

        return ApiResponse::success($projects);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();
        $base = Project::whereIn('company_id', $companyIds);

        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        // A draft/unpaid carries a deadline from nobody — and isn't started
        // work — so it must not be counted overdue alongside completed/cancelled.
        $overdue = (clone $base)->where('deadline', '<', now())
            ->whereNotIn('status', ['draft', 'unpaid', 'completed', 'cancelled'])
            ->count();

        $assignedToMe = (clone $base)->where('project_manager_id', $this->admin()->id ?? 0)->count();

        return ApiResponse::success([
            'total'        => (clone $base)->count(),
            // 'unpaid'/'draft' are legacy pre-activation statuses from before
            // project creation moved entirely behind payment (see
            // App\Services\PaymentProjectStartService::createActiveProject())
            // — kept here so any such row from before that change still
            // counts correctly; nothing creates new ones anymore.
            'unpaid'       => $counts['unpaid'] ?? 0,
            'draft'        => $counts['draft'] ?? 0,
            'planning'     => $counts['planning'] ?? 0,
            'active'       => $counts['active'] ?? 0,
            'on_hold'      => $counts['on_hold'] ?? 0,
            'completed'    => $counts['completed'] ?? 0,
            'cancelled'    => $counts['cancelled'] ?? 0,
            'overdue'      => $overdue,
            'assigned'     => $assignedToMe,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();
        $requestedCompanyId = (int) $request->input('company_id');

        $validated = $request->validate([
            'company_id'         => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'client_id'           => ['nullable', 'integer', 'exists:clients,id'],
            'lead_id'             => ['nullable', 'integer', 'exists:leads,id'],
            'invoice_id'          => ['nullable', 'integer', 'exists:invoices,id'],
            // Must be an active member of THIS project's company specifically
            // — not just any company this admin owns (companyIds() is too
            // broad when the admin owns multiple companies).
            'project_manager_id'  => [
                'nullable', 'integer',
                Rule::exists('company_user_assignments', 'user_id')
                    ->where('company_id', $requestedCompanyId)
                    ->where('status', 'active'),
            ],
            'name'                => ['required', 'string', 'max:255'],
            // Which of the company's services this project delivers. Optional,
            // and its own company's service only — tagging a project with
            // another company's service would leak the name across tenants.
            //
            // This is what makes the Client Portal's recommendations exact
            // rather than guessed from the project name (see
            // Services\ServiceRecommendationService).
            'company_service_id'  => [
                'nullable', 'integer',
                Rule::exists('company_services', 'id')
                    ->where('company_id', $requestedCompanyId)
                    ->whereNull('deleted_at'),
            ],
            // See the matching note in Api\User\ProjectController — a `text`
            // column overflow guard for the now markup-formatted description.
            'description'         => ['nullable', 'string', 'max:20000'],
            'status'              => ['nullable', 'in:planning,active,on_hold,completed,cancelled'],
            'priority'            => ['nullable', 'in:low,medium,high,urgent'],
            'budget'              => ['nullable', 'numeric', 'min:0'],
            'deadline'            => ['nullable', 'date'],
        ], [
            'project_manager_id.exists' => 'Selected user does not belong to this company.',
            'company_service_id.exists' => 'Selected service does not belong to this company.',
        ]);

        $validated['status']   ??= 'active';
        $validated['priority'] ??= 'medium';
        // The project's Start Date is always the moment it was created —
        // never a separately chosen date.
        $validated['start_date'] = now()->toDateString();
        // created_by FKs to `users` (staff) only; the Company Admin creating
        // this project isn't a `users` row, so record them via the parallel
        // created_by_admin_id column instead (created_by stays null/reserved
        // for a future staff-creation path, matching this module's dual
        // admin/user actor-tracking convention).
        $validated['created_by_admin_id'] = $this->admin()->id;

        // A lead named here without an explicit client_id has usually already
        // been converted to a Client (Api\Admin\LeadController::convert()) —
        // without this, the project would carry lead_id but a null
        // client_id, making it permanently invisible to the Client Portal
        // (Api\Client\ProjectController filters strictly on client_id, no
        // lead_id fallback).
        if (empty($validated['client_id']) && !empty($validated['lead_id'])) {
            $validated['client_id'] = \App\Models\Lead::find($validated['lead_id'])?->client?->id;
        }

        $project = DB::transaction(function () use ($validated) {
            $project = Project::create($validated);
            $this->createProjectFolders($project);
            return $project;
        });

        // Complete the back-link so the invoice shows up in the project's
        // own invoices() list (and billing summary), not just as the
        // project's single "originating invoice" field.
        if (!empty($validated['invoice_id'])) {
            \App\Models\Invoice::where('id', $validated['invoice_id'])->update(['project_id' => $project->id]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => 'created',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => $validated,
        ]);

        $project->logActivity('created', "Project \"{$project->name}\" created by {$this->adminName()}.", $this->adminName(), [
            'created_by_admin_id' => $this->admin()->id,
        ]);

        // Every project must have a Compliance Case — never allowed to block
        // or duplicate project creation, see ComplianceCaseService.
        try {
            \App\Services\ComplianceCaseService::ensureCaseForProject($project);
        } catch (\Throwable $e) {
            Log::error('[compliance] project ' . $project->id . ' create: ' . $e->getMessage());
        }

        if ($project->project_manager_id) {
            $this->addAsProjectManager($project, $project->project_manager_id);
            $managerName = User::find($project->project_manager_id)?->name ?? 'Unknown';
            $project->logActivity('manager_assigned', "{$this->adminName()} assigned {$managerName} as project manager.", $this->adminName(), [
                'to' => $project->project_manager_id,
            ]);

            Notification::create([
                'user_id'    => $project->project_manager_id,
                'company_id' => $project->company_id,
                'type'       => 'project_assigned',
                'title'      => 'New project assigned',
                'body'       => "You were assigned as project manager for \"{$project->name}\".",
                // Recipient is always a real User (PM) — never Admin — so the
                // link must be the User-guard route, not /admin/projects/...
                'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);

            // Email alongside the bell notification — non-blocking, see
            // AssignmentMailService.
            AssignmentMailService::projectManagerAssigned($project, $project->project_manager_id, $this->adminName());
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project created', 201);
    }

    // Whoever is set as project_manager_id must also have a ProjectTeamMember
    // row, or the staff-guard visibility scope (project_manager_id OR
    // teamMembers OR assigned task) never actually grants them access via the
    // team-membership leg — keeps both signals in sync automatically.
    private function addAsProjectManager(Project $project, int $userId): void
    {
        // holdingRole(): see the identical tagging (and its reasoning) in
        // Api\User\ProjectController::store() — a Seller set as
        // project_manager_id (e.g. a self-handoff project) belongs in Sales
        // Team Resources, not Production.
        $isSeller = User::where('id', $userId)->holdingRole($project->company_id, 'seller')->exists();
        ProjectTeamMember::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $userId],
            // assigned_by FKs to `users`; Company Admin actor isn't a User row
            ['role_in_project' => 'project_manager', 'team' => $isSeller ? 'sales' : 'production', 'assigned_by' => null]
        );
    }

    // Full relation set + computed progress/billing_summary — the single
    // source of truth for what a Project API response looks like, used by
    // show() AND every lifecycle action (create/update/activate/complete/
    // close/reopen/approveDelivery). Before this, those actions returned a
    // bare or partially-loaded $project->fresh(), so the frontend's
    // onUpdated(updated) => setProject(updated) wiped out created_by_admin/
    // client/seller/tasks/etc from the page's state, and reset progress to
    // its raw (never-persisted) column value, until the next full reload.
    private function presentProject(Project $project): Project
    {
        $project->load([
            'client:id,name,email',
            // A project handed off before its Lead converted to a Client has
            // no client_id yet — the frontend falls back to this, then to
            // invoice.customer_name for a fully guest (no Lead either) project.
            'lead:id,name',
            'invoice:id,invoice_number,total_amount,status,customer_email,customer_name',
            'invoices:id,invoice_number,subtotal,tax_amount,total_amount,paid_amount,status,due_date,currency,project_id',
            // Billing tab's "Partial payment history" — every payment row
            // recorded against each of this project's invoices, same fields
            // already shown on the invoice detail page's own Payments list.
            'invoices.payments' => fn ($q) => $q->orderByDesc('payment_date')->orderByDesc('id'),
            'projectManager:id,name,role_type',
            'seller:id,name,email',
            'createdBy:id,name',
            'createdByAdmin:id,name',
            'deliverySubmittedBy:id,name',
            'deliveryApprovedByAdmin:id,name',
            'completionApprovedByAdmin:id,name',
            'reopenRequestedBy:id,name',
            'tasks' => fn($q) => $q->with('assignedTo:id,name'),
            'teamMembers.user:id,name,role_type,custom_role_label',
            'folders' => fn($q) => $q->whereNull('parent_folder_id')->orderBy('sort_order'),
            'deliverables',
        ]);

        $totalTasks = $project->tasks->count();
        $doneTasks  = $project->tasks->where('status', 'completed')->count();
        // A completed/closed project reads 100% regardless of its task
        // ratio — see the same override in index() above.
        $project->progress = in_array($project->status, ['completed', 'closed'], true)
            ? 100
            : ($totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0);

        $totalInvoiced = (float) $project->invoices->sum('total_amount');
        $totalPaid     = (float) $project->invoices->sum('paid_amount');
        $project->billing_summary = [
            'total_subtotal' => (float) $project->invoices->sum('subtotal'),
            'total_tax'      => (float) $project->invoices->sum('tax_amount'),
            'total_invoiced' => $totalInvoiced,
            'total_paid'     => $totalPaid,
            'outstanding'    => max(0, round($totalInvoiced - $totalPaid, 2)),
        ];

        return $project;
    }

    public function show(Request $request, int $id): JsonResponse
    {
        // Company-Wise Dashboard Filtering — a project belonging to a
        // company other than the active one (or any owned company, when
        // "All Companies" is selected) must not be directly reachable
        // either, same policy as index()'s listing. Previously scoped to
        // every owned company regardless of the active-company selector, so
        // a direct link/URL to another company's project still opened fine.
        $project = Project::whereIn('company_id', $this->activeCompanyIds())->findOrFail($id);

        return ApiResponse::success($this->presentProject($project));
    }

    // POST /admin/projects/{id}/invoices/link — attach an existing invoice
    // (deposit/milestone/final/change-request) to a project.
    public function linkInvoice(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $validated = $request->validate(['invoice_id' => ['required', 'integer']]);

        $invoice = \App\Models\Invoice::where('company_id', $project->company_id)->find($validated['invoice_id']);
        if (!$invoice) {
            return ApiResponse::error('Invoice not found.', 404);
        }
        if ($invoice->project_id && $invoice->project_id !== $project->id) {
            return ApiResponse::error('This invoice is already linked to another project.', 422);
        }

        $invoice->update(['project_id' => $project->id]);
        $project->logActivity('invoice_linked', "{$this->adminName()} linked invoice {$invoice->invoice_number} to this project.", $this->adminName(), [
            'invoice_id' => $invoice->id,
        ]);

        return ApiResponse::success(null, 'Invoice linked to project');
    }

    // DELETE /admin/projects/{id}/invoices/{invoiceId}
    public function unlinkInvoice(int $id, int $invoiceId): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $invoice = \App\Models\Invoice::where('project_id', $project->id)->find($invoiceId);

        if (!$invoice) {
            return ApiResponse::error('Invoice not found on this project.', 404);
        }

        $invoice->update(['project_id' => null]);
        $project->logActivity('invoice_unlinked', "{$this->adminName()} unlinked invoice {$invoice->invoice_number} from this project.", $this->adminName(), [
            'invoice_id' => $invoice->id,
        ]);

        return ApiResponse::success(null, 'Invoice unlinked from project');
    }

    // POST /admin/projects/{id}/invoices — create a brand-new invoice already
    // linked to this project ("Create Invoice for this Project"). Mirrors
    // Api\User\ProjectController::createInvoice() exactly, adapted for the
    // Admin guard (no permission check — Company Admin already has full
    // authority; created_by_admin_id instead of created_by, since Admin
    // isn't a `users` row).
    public function createInvoice(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $admin = $this->admin();

        // The invoice is always emailed immediately once created below — to
        // the project's own client if it has one, otherwise to a one-off
        // address collected right here (a project can legitimately have no
        // client, see 2026_07_04_000003_make_projects_client_id_nullable).
        $data = $request->validate([
            'due_date'            => 'nullable|date',
            // USD is the system's only supported currency now.
            'currency'            => 'nullable|in:USD',
            'tax_rate'            => 'nullable|numeric|min:0|max:100',
            'discount_amount'     => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:2000',
            // What this invoice is FOR (e.g. "50% Advance Payment") — shown to
            // the client on the payment page, the invoice email and the portal,
            // so a milestone invoice isn't just a bare amount with no
            // explanation. Same field the standalone New Invoice form sends.
            'invoice_purpose'     => 'nullable|string|max:255',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'recipient_email'     => [$project->client_id ? 'nullable' : 'required', 'email', 'max:255'],
        ], [
            'recipient_email.required' => 'This project has no linked client — an email address is required to send the invoice.',
        ]);

        // Settings > Invoice tab defaults — the same profile the invoice-number
        // prefix and currency below already come from. tax_rate and due_date
        // used to fall back to a hardcoded 0/null here, so a project invoice
        // silently ignored the company's configured tax rate and payment
        // terms even though Api\Admin\InvoiceController::store() (the
        // standalone New Invoice form) has always applied both — one company's
        // two invoice flows produced different numbers.
        $profile = \App\Models\Company::find($project->company_id)?->invoicingProfile() ?? [];

        $taxRate  = (float) ($data['tax_rate'] ?? $profile['invoice_tax_rate'] ?? 0);
        $discount = (float) ($data['discount_amount'] ?? 0);

        // Payment Terms (days) — only ever fills a due_date the caller didn't
        // set explicitly; issue date is "now", the same reference point the
        // invoice itself is created at.
        $dueDate = $data['due_date'] ?? (
            !empty($profile['invoice_payment_terms'])
                ? now()->addDays((int) $profile['invoice_payment_terms'])->toDateString()
                : null
        );

        $subtotal = 0;
        $items    = $data['items'];
        foreach ($items as &$item) {
            $item['total'] = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            $subtotal += $item['total'];
        }
        unset($item);

        if ($subtotal <= 0) {
            return ApiResponse::error('Invoice amount must be greater than 0', 422);
        }

        $taxAmt = round($subtotal * $taxRate / 100, 2);

        $year   = now()->year;
        $prefix = $profile['invoice_prefix'] ?? 'INV';
        $last   = \App\Models\Invoice::whereYear('created_at', $year)->where('invoice_number', 'like', "{$prefix}-{$year}-%")->latest('id')->value('invoice_number');
        $seq    = $last ? ((int) substr($last, -4)) + 1 : 1;
        do {
            $number = sprintf('%s-%d-%04d', $prefix, $year, $seq++);
        } while (\App\Models\Invoice::where('invoice_number', $number)->exists());

        // A milestone invoice must match whatever currency this project has
        // already been invoiced in — never a hardcoded default — so its
        // amounts (and, transitively, paid_amount, which carries no currency
        // of its own) read consistently against every other invoice on this
        // project. A project with no prior invoice at all falls back to
        // Company Admin's own configured currency, then the request's own
        // currency, then USD as the last resort.
        $existingCurrency = $project->invoices()->oldest('created_at')->value('currency')
            ?? $profile['currency'] ?? null;

        // The frontend never sends a conflicting value (it reads/sends this
        // same $existingCurrency back), but reject rather than silently
        // discard it for any other caller — same "explicit error over
        // silent correction" rule as the payment-currency mismatch guard in
        // InvoicePaymentService::applyToInvoice().
        if ($existingCurrency && !empty($data['currency']) && strcasecmp($data['currency'], $existingCurrency) !== 0) {
            return ApiResponse::error(
                "This project's invoices are already in {$existingCurrency} — a new invoice can't be created in {$data['currency']} instead.",
                422
            );
        }

        $invoice = \App\Models\Invoice::create([
            'company_id'          => $project->company_id,
            'client_id'           => $project->client_id,
            'lead_id'             => $project->lead_id,
            'project_id'          => $project->id,
            'created_by_admin_id' => $admin->id,
            'invoice_number'      => $number,
            'subtotal'            => $subtotal,
            'tax_rate'            => $taxRate,
            'tax_amount'          => $taxAmt,
            'discount_amount'     => $discount,
            'total_amount'        => $subtotal + $taxAmt - $discount,
            'paid_amount'         => 0,
            'currency'            => $existingCurrency ?? $data['currency'] ?? 'USD',
            'status'              => 'draft',
            'due_date'            => $dueDate,
            'notes'               => $data['notes']           ?? null,
            'invoice_purpose'     => $data['invoice_purpose'] ?? null,
            // Recorded only for a no-client project — a client-linked
            // invoice always resolves its recipient from client.email
            // instead, same as everywhere else in the app.
            'customer_name'       => $project->client_id ? null : $project->name,
            'customer_email'      => $project->client_id ? null : $data['recipient_email'],
        ]);

        foreach ($items as $i => $item) {
            \App\Models\InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => $item['total'],
                'sort_order'  => $i,
            ]);
        }

        $project->logActivity('invoice_created', "{$this->adminName()} created invoice {$invoice->invoice_number} for this project.", $this->adminName(), [
            'invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
        ]);

        // Send it right away — the project's own client if there is one,
        // else the one-off address collected above. Mirrors
        // Api\User\ProjectController::createInvoice() exactly.
        $recipientEmail = $project->client?->email ?? $data['recipient_email'];

        $company     = \App\Models\Company::find($project->company_id);
        $invoice->generatePublicToken(30);
        $invoice->refresh();
        $paymentUrl  = FrontendUrl::to('pay/invoice/' . $invoice->payment_token);
        $companyName = $company->invoicingProfile()['name'];

        try {
            Mail::to($recipientEmail)->send(new \App\Mail\InvoiceMail($invoice, $paymentUrl, $companyName));
        } catch (\Throwable $e) {
            Log::error('[admin-project-invoice] email send failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            $responseData = $invoice->load('items')->toArray();
            $responseData['payment_url'] = $paymentUrl;
            return ApiResponse::success($responseData, 'Invoice created, but the email could not be sent. You can copy the payment link below or resend it from the invoice page.', 201);
        }

        $invoice->update(['status' => 'sent', 'sent_at' => now()]);
        \App\Services\InvoiceNotificationService::notifyClientInvoiceSent($invoice);

        $project->logActivity('invoice_sent', "Invoice {$invoice->invoice_number} sent to {$recipientEmail}.", $this->adminName(), [
            'invoice_number' => $invoice->invoice_number, 'sent_to' => $recipientEmail,
        ]);

        $responseData = $invoice->load('items')->toArray();
        $responseData['payment_url'] = $paymentUrl;
        $responseData['sent_to']     = $recipientEmail;

        return ApiResponse::success($responseData, "Invoice created and sent to {$recipientEmail}", 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyIds = $this->companyIds();
        $project = Project::whereIn('company_id', $companyIds)->findOrFail($id);

        // Closed is a terminal, read-only state — only reopen() can move a
        // project out of it. Blocking here (not just hiding the button in the
        // UI) matches the "Closed project becomes read-only" requirement.
        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'client_id'           => ['sometimes', 'nullable', 'integer', 'exists:clients,id'],
            'invoice_id'          => ['nullable', 'integer', 'exists:invoices,id'],
            // Must belong to THIS project's own company — not just any
            // company this admin owns.
            'project_manager_id'  => [
                'nullable', 'integer',
                Rule::exists('company_user_assignments', 'user_id')
                    ->where('company_id', $project->company_id)
                    ->where('status', 'active'),
            ],
            'name'                => ['sometimes', 'string', 'max:255'],
            // Which service this project delivers — editable so existing
            // projects can be tagged after the fact, which is what turns
            // guessed Client Portal recommendations into exact ones. This
            // project's own company only.
            'company_service_id'  => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('company_services', 'id')
                    ->where('company_id', $project->company_id)
                    ->whereNull('deleted_at'),
            ],
            // See the matching note in Api\User\ProjectController — a `text`
            // column overflow guard for the now markup-formatted description.
            'description'         => ['nullable', 'string', 'max:20000'],
            // 'completed' and 'closed' are reached only via complete()/close()
            // below (readiness checks, activity log, notifications) — never as
            // a bare status write through this generic update endpoint.
            'status'              => ['sometimes', 'in:planning,active,on_hold,blocked,cancelled'],
            'priority'            => ['sometimes', 'in:low,medium,high,urgent'],
            'budget'              => ['nullable', 'numeric', 'min:0'],
            // Start Date is fixed at project-creation time — never editable
            // here.
            'deadline'            => ['nullable', 'date'],
        ], [
            'project_manager_id.exists' => 'Selected user does not belong to this company.',
            'company_service_id.exists' => 'Selected service does not belong to this company.',
        ]);

        $wasManagerId = $project->project_manager_id;
        $before = $project->only(array_keys($validated));

        $project->update($validated);

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => 'updated',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => $validated,
        ]);

        if (isset($validated['project_manager_id']) && $validated['project_manager_id'] !== $wasManagerId) {
            $oldManagerName = $wasManagerId ? (User::find($wasManagerId)?->name ?? 'Unknown') : null;
            $newManagerName = $validated['project_manager_id'] ? (User::find($validated['project_manager_id'])?->name ?? 'Unknown') : null;
            $managerType = $newManagerName ? ($oldManagerName ? 'manager_switched' : 'manager_assigned') : 'manager_unassigned';
            $managerDescription = $newManagerName
                ? ($oldManagerName
                    ? "{$this->adminName()} changed project manager from {$oldManagerName} to {$newManagerName}."
                    : "{$this->adminName()} assigned {$newManagerName} as project manager.")
                : "{$this->adminName()} unassigned project manager {$oldManagerName}.";
            $project->logActivity($managerType, $managerDescription, $this->adminName(), [
                'from' => $wasManagerId,
                'to' => $validated['project_manager_id'],
            ]);

            // Notification.user_id is NOT NULL — unassigning (new value null,
            // e.g. via the Projects listing's "Unassigned" option) must never
            // reach the Notification::create below, or it throws a DB
            // constraint violation.
            if ($validated['project_manager_id']) {
                $this->addAsProjectManager($project, $validated['project_manager_id']);

                Notification::create([
                    'user_id'    => $validated['project_manager_id'],
                    'company_id' => $project->company_id,
                    'type'       => 'project_assigned',
                    'title'      => 'Project assigned to you',
                    'body'       => "You were assigned as project manager for \"{$project->name}\".",
                    'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
                ]);

                AssignmentMailService::projectManagerAssigned($project, $validated['project_manager_id'], $this->adminName());
            }
        }

        $changedFields = collect(array_keys($validated))
            ->filter(fn ($field) => $field !== 'project_manager_id' && ($before[$field] ?? null) != $project->{$field})
            ->values();

        if ($changedFields->contains('status')) {
            $project->logActivity('status_changed', "{$this->adminName()} changed status from {$before['status']} to {$project->status}.", $this->adminName(), [
                'from' => $before['status'] ?? null,
                'to' => $project->status,
            ]);
        }

        $otherFields = $changedFields->reject(fn ($field) => $field === 'status')->values();
        if ($otherFields->isNotEmpty()) {
            $project->logActivity('updated', "{$this->adminName()} updated " . $otherFields->implode(', ') . '.', $this->adminName(), [
                'fields' => $otherFields->all(),
            ]);
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        // The project row itself is only soft-deleted (SoftDeletes, no
        // restore feature exists) — but its storage folder and everything
        // in it (attachments, deliverables, delivery packages, etc.) are
        // real files that would otherwise sit on disk forever with nothing
        // left in the app pointing at them. Remove the folder tree plus its
        // ProjectFolder metadata rows now, while $project->storage_folder is
        // still known.
        if ($project->storage_folder) {
            Storage::deleteDirectory($project->storage_folder);
        }
        ProjectFolder::where('project_id', $project->id)->delete();

        $project->delete();

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => 'deleted',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
        ]);

        return ApiResponse::success(null, 'Project deleted');
    }

    public function assignTeam(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        // Team members must be active members of THIS project's own company —
        // not just any company this admin owns (a multi-company admin could
        // otherwise pull a Company B user onto a Company A project).
        $validated = $request->validate([
            'members'                    => ['required', 'array'],
            'members.*.user_id'          => [
                'required', 'integer',
                Rule::exists('company_user_assignments', 'user_id')
                    ->where('company_id', $project->company_id)
                    ->where('status', 'active'),
            ],
            'members.*.role_in_project'  => ['required', 'in:project_manager,production_user,team_member,reviewer'],
        ], [
            'members.*.user_id.exists' => 'Selected user does not belong to this company.',
        ]);

        // THE PRIMARY SELLER IS NOT A TEAM RESOURCE. Mirrors the same guard in
        // Api\User\ProjectController::assignTeam() — see the long note there.
        // projects.seller_id is the project's main seller; a team row for the
        // same person would list them twice, once as the seller and again as
        // one of their own secondary sellers.
        $submittedIds = collect($validated['members'])->pluck('user_id')->unique();
        if ($project->seller_id && $submittedIds->contains((int) $project->seller_id)) {
            return ApiResponse::error('This user is already the project\'s seller, so they can\'t also be added as a team resource.', 422);
        }

        // A SELLER IS NEVER A PRODUCTION RESOURCE. Also mirrors the user guard:
        // holding the Seller role makes someone Sales-associated whatever else
        // they hold, and the `team` column below already derives 'sales' for
        // them — this refuses the one request that would contradict it.
        $productionSubmittedIds = collect($validated['members'])
            ->filter(fn ($m) => $m['role_in_project'] === 'production_user')
            ->pluck('user_id');
        if ($productionSubmittedIds->isNotEmpty()) {
            $sellerRoleIds = User::whereIn('id', $productionSubmittedIds)
                ->holdingRole($project->company_id, 'seller')
                ->pluck('id');
            if ($sellerRoleIds->isNotEmpty()) {
                return ApiResponse::error('A user who holds the Seller role can\'t be added as a Production Team resource.', 422);
            }
        }

        // A Seller may sit on the general project team (2026-09-15, on request
        // — they were excluded before). projects.seller_id and
        // ProjectSellerAssignmentService remain the way a project's OWN Seller
        // is assigned; this is the separate "put this Seller on the team" path,
        // and the two are independent.
        //
        // Adding a Seller here used to cost them their client-facing chat:
        // ProjectChatController::projectForChat() matched team membership
        // before the Seller branch and resolved them as an internal 'worker',
        // a mode that can never reach the client-facing slice. That resolver
        // now skips the internal branch for a Seller who holds client access,
        // so the two can coexist — do not re-order it back.
        $eligibleRoles = ['project_manager', 'production', 'designer', 'developer', 'qa', 'team_member', 'seller'];
        // holdingRole(), not whereNotIn('role_type'): role_type carries only a
        // person's PRIMARY role, so a Developer or QA who holds that job as a
        // SECOND role was refused here even though they genuinely hold it in
        // this company. Widening only — Seller stays off $eligibleRoles, so the
        // "a Seller is never a team_members row" rule above is untouched, and a
        // user with no eligible role at all is still refused.
        $disallowedIds = User::whereIn('id', collect($validated['members'])->pluck('user_id'))
            ->whereNot(fn ($q) => $q->holdingRole($project->company_id, $eligibleRoles))
            ->pluck('id');
        if ($disallowedIds->isNotEmpty()) {
            return ApiResponse::error("This user's role isn't eligible for a project team.", 422);
        }

        // A project may only ever have ONE user filling the literal
        // role_in_project='project_manager' seat — the chat hub
        // (isPM()/isProjectPmUser()) and projects.project_manager_id both
        // assume exactly one. Mirrors the same cap in
        // Api\User\ProjectController::assignTeam().
        //
        // Counted on role_in_project (2026-09-28, on request — previously
        // counted on the candidate's intrinsic role_type, which refused ANY
        // second person whose company-wide role happens to be Project
        // Manager, even added as a plain production_user). "PM Design"/"PM
        // Developer"/"PM SEO" all hold role_type='project_manager' company-
        // wide but are added here as production_user, so they no longer
        // compete for this one seat — see PRODUCTION_ELIGIBLE_ROLES on the
        // frontend team page.
        //
        // Checked against the union of already-existing members' role
        // (unless THIS payload overrides it) and this request's payload, so
        // re-submitting the SAME existing PM (e.g. a role change save) never
        // trips it, but adding a second, different one does.
        $submittedByUserId = collect($validated['members'])->keyBy('user_id');
        $existingPmUserIds = $project->teamMembers()
            ->where('role_in_project', 'project_manager')
            ->pluck('user_id');
        $resultingPmUserIds = $existingPmUserIds
            ->reject(fn ($id) => $submittedByUserId->has($id) && $submittedByUserId[$id]['role_in_project'] !== 'project_manager')
            ->merge($submittedByUserId->filter(fn ($m) => $m['role_in_project'] === 'project_manager')->pluck('user_id'))
            ->unique();
        if ($resultingPmUserIds->count() > 1) {
            return ApiResponse::error('A project can only have one Project Manager on its team.', 422);
        }

        // SELLERS ARE NO LONGER CAPPED (2026-09-15, on request). A project is
        // staffed with as many Sellers as the work needs, so the second and
        // third no longer 422. Nothing about adding one touches the others:
        // this endpoint only ever upserts the ids in THIS payload
        // (updateOrCreate below, never a sync/delete), and the
        // (project_id, user_id) unique index makes a duplicate impossible.
        // The project's own linked Seller (projects.seller_id) is a separate
        // thing entirely and is unaffected either way.

        foreach ($validated['members'] as $member) {
            $existingMember = ProjectTeamMember::where('project_id', $project->id)
                ->where('user_id', $member['user_id'])
                ->first();
            $wasRole = $existingMember?->role_in_project;
            $memberUser = User::find($member['user_id']);
            // holdingRole(), not role_type: a user whose Seller role is a
            // SECOND role (role_type carries only their primary) must still
            // count as a Seller here, both for the Sales/Production grouping
            // below and for the "Seller never auto-added to chat" guard.
            $memberIsSeller = $memberUser
                && User::where('id', $memberUser->id)->holdingRole($project->company_id, 'seller')->exists();

            ProjectTeamMember::updateOrCreate(
                ['project_id' => $project->id, 'user_id' => $member['user_id']],
                [
                    'role_in_project' => $member['role_in_project'],
                    // Sales vs Production resource grouping — independent of
                    // role_in_project (a "PM Sales" designation still holds
                    // the Project Manager role, but belongs in Sales). See
                    // the 2026_09_28 migration's doc-comment.
                    'team' => $memberIsSeller ? 'sales' : 'production',
                    // assigned_by FKs to `users`; Company Admin actor isn't a User row
                    'assigned_by' => null,
                ]
            );
            $memberName = $memberUser?->name ?? 'Unknown';

            // Being formally added to the team is itself the authorization
            // to chat — see ProjectChatService::addTeamMember(). Never for a
            // Seller: mirrors syncFormalTeamParticipants()'s hard "Seller
            // can never be auto-added" rule (added instead only via the
            // explicit Manage Participants path).
            if ($memberUser && !$memberIsSeller) {
                ProjectChatService::addTeamMember($project, $memberUser->id);
            }

            if (!$existingMember) {
                $project->logActivity('team_assigned', "{$this->adminName()} added {$memberName} to the project team as " . str_replace('_', ' ', $member['role_in_project']) . '.', $this->adminName(), [
                    'to' => $member['user_id'],
                    'role_in_project' => $member['role_in_project'],
                ]);
            } elseif ($wasRole !== $member['role_in_project']) {
                $project->logActivity('team_member_role_changed', "{$this->adminName()} changed {$memberName}'s project role from " . str_replace('_', ' ', $wasRole) . ' to ' . str_replace('_', ' ', $member['role_in_project']) . '.', $this->adminName(), [
                    'user_id' => $member['user_id'],
                    'from' => $wasRole,
                    'to' => $member['role_in_project'],
                ]);
            }

            Notification::create([
                'user_id'    => $member['user_id'],
                'company_id' => $project->company_id,
                'type'       => 'project_team_assigned',
                'title'      => 'Added to project team',
                'body'       => "You were added to the team for \"{$project->name}\".",
                'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => 'team_assigned',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($project->teamMembers()->with('user:id,name,role_type')->get(), 'Team updated');
    }

    // PATCH /admin/projects/{id}/team/{memberId} — change an already-added
    // member's role without removing and re-adding them.
    public function updateMemberRole(Request $request, int $id, int $memberId): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $member  = $project->teamMembers()->findOrFail($memberId);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'role_in_project' => ['required', 'in:project_manager,production_user,team_member,reviewer'],
        ]);

        $wasRole = $member->role_in_project;
        if ($validated['role_in_project'] === $wasRole) {
            return ApiResponse::success($member->fresh('user:id,name,role_type'), 'Role unchanged');
        }

        $member->update(['role_in_project' => $validated['role_in_project']]);

        // Keep projects.project_manager_id in sync when a member is promoted
        // to Project Manager via this control — mirrors the one-directional
        // sync addAsProjectManager() already does from the other direction.
        if ($validated['role_in_project'] === 'project_manager' && $project->project_manager_id !== $member->user_id) {
            $project->update(['project_manager_id' => $member->user_id]);

            // This promotion IS a PM appointment, so it earns the same email
            // the Edit Project / Create Project paths send. The generic
            // 'project_role_changed' bell notification below still covers
            // every other role change on its own.
            AssignmentMailService::projectManagerAssigned($project, $member->user_id, $this->adminName());
        }

        Notification::create([
            'user_id'    => $member->user_id,
            'company_id' => $project->company_id,
            'type'       => 'project_role_changed',
            'title'      => 'Project role updated',
            'body'       => "Your role on \"{$project->name}\" was changed to " . str_replace('_', ' ', $validated['role_in_project']) . '.',
            'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
        ]);

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // Company Admin actor isn't a User row
            'action'      => 'team_member_role_changed',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'old_values'  => ['user_id' => $member->user_id, 'role_in_project' => $wasRole],
            'new_values'  => ['user_id' => $member->user_id, 'role_in_project' => $validated['role_in_project']],
        ]);
        $memberName = $member->user?->name ?? User::find($member->user_id)?->name ?? 'Unknown';
        $project->logActivity('team_member_role_changed', "{$this->adminName()} changed {$memberName}'s project role from " . str_replace('_', ' ', $wasRole) . ' to ' . str_replace('_', ' ', $validated['role_in_project']) . '.', $this->adminName(), [
            'user_id' => $member->user_id,
            'from' => $wasRole,
            'to' => $validated['role_in_project'],
        ]);

        return ApiResponse::success($member->fresh('user:id,name,role_type'), 'Role updated');
    }

    public function removeTeamMember(int $id, int $memberId): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $member  = $project->teamMembers()->findOrFail($memberId);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $removedUserId = $member->user_id;
        $removedUserName = $member->user?->name ?? User::find($removedUserId)?->name ?? 'Unknown';
        $member->delete();

        // Losing their team row can also mean losing their project chat
        // access — but only if they're not still tied to the project some
        // other way (e.g. still assigned to an open task).
        ProjectChatService::removeParticipantIfNoLongerEligible($project, $removedUserId);

        // A removed PM/Seller must not leave a dangling project_manager_id/
        // seller_id reference — otherwise assignTeam()'s one-PM/one-Seller
        // cap (which only counts CURRENT team rows) stops seeing them,
        // silently letting a second PM/Seller be added while the stale FK
        // still points at the one who's no longer on the team.
        $fkUpdates = [];
        if ((int) $project->project_manager_id === (int) $removedUserId) $fkUpdates['project_manager_id'] = null;
        if ((int) $project->seller_id === (int) $removedUserId) $fkUpdates['seller_id'] = null;
        if ($fkUpdates) $project->update($fkUpdates);

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // Company Admin actor isn't a User row
            'action'      => 'team_member_removed',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'old_values'  => ['user_id' => $removedUserId],
        ]);
        $project->logActivity('team_member_removed', "{$this->adminName()} removed {$removedUserName} from the project team.", $this->adminName(), [
            'user_id' => $removedUserId,
        ]);

        return ApiResponse::success(null, 'Team member removed');
    }

    private function completionService(): ProjectCompletionService
    {
        return app(ProjectCompletionService::class);
    }

    // GET /admin/projects/{id}/completion-status — backs the "Mark as
    // Complete" pre-flight checklist modal on the frontend.
    public function completionStatus(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $service = $this->completionService();
        $readiness = $service->checkReadiness($project);

        return ApiResponse::success([
            'status'             => $project->status,
            'ready'              => $readiness['ready'],
            'blockers'           => $readiness['blockers'],
            'has_unpaid_invoice' => $service->hasUnpaidInvoice($project),
        ]);
    }

    // Activate a draft project — the name-only stub auto-created when a client's
    // invoice payment starts one (see App\Services\PaymentProjectStartService).
    // Until this runs the project is invisible to the client portal and to any
    // sub-user without canActivateProjects. Company Admin is always structurally
    // allowed, same as complete/close/reopen below.
    //
    // Folders are created here rather than at auto-creation time: a draft that
    // nobody activates shouldn't leave storage behind.
    public function activate(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if ($project->status !== 'draft') {
            return ApiResponse::error("Only a draft project can be activated — this one is {$project->status}.", 422);
        }

        $project->update(['status' => 'active']);

        if ($project->folders()->count() === 0) {
            $this->createProjectFolders($project);
        }

        $project->logActivity('activated', "Draft project activated by {$this->adminName()}.", $this->adminName());

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'activated', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
        ]);

        $this->notifyLifecycle($project, 'project_activated', 'Project activated', "\"{$project->name}\" was activated by {$this->adminName()}.");

        $companyName = \App\Models\Company::find($project->company_id)?->invoicingProfile()['name'] ?? config('app.name');
        $this->completionService()->notifyClientOfActivation($project, $companyName);

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project activated');
    }

    // Company Admin is always structurally allowed to complete/close/reopen —
    // no permission gate here, mirroring how this guard never checks
    // UserCompanyPermission anywhere else in this controller.
    public function complete(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        // A draft (or still-unpaid placeholder) has no work to complete — it
        // must be activated first.
        if ($project->isDraft()) {
            return ApiResponse::error("Activate this project before completing it — it is currently {$project->status}.", 422);
        }

        if (in_array($project->status, ['completed', 'approved_locked', 'closed'])) {
            return ApiResponse::error("Project is already {$project->status}.", 422);
        }

        $readiness = $this->completionService()->checkReadiness($project);
        if (!$readiness['ready']) {
            return ApiResponse::error('Project cannot be completed yet — outstanding work remains.', 422, ['blockers' => $readiness['blockers']]);
        }

        $project->update([
            'status'                => 'completed',
            'completed_at'          => now(),
            'completed_by_admin_id' => $this->admin()->id,
        ]);

        $project->logActivity('completed', "Project marked as completed by {$this->adminName()}.", $this->adminName());

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'completed', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
        ]);

        $this->notifyLifecycle($project, 'project_completed', 'Project completed', "\"{$project->name}\" was marked as completed by {$this->adminName()}.");

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project marked as completed');
    }

    // Approves a PM-completed project into the new 'approved_locked' state —
    // the Project Approval Lock: PM edits (details/tasks/timesheets/
    // deliverables/attachments — see Project::isLocked() guards across
    // Api\User\*) are blocked from here on; only chat/comments/viewing stay
    // open. Only Admin can reopen it (or approve a PM's requestReopen()) —
    // see reopen() below.
    public function approveCompletion(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if ($project->status !== 'completed') {
            return ApiResponse::error('Only a completed project can be approved and locked.', 422);
        }

        $project->update([
            'status'                          => 'approved_locked',
            'completion_approved_at'          => now(),
            'completion_approved_by_admin_id' => $this->admin()->id,
        ]);

        $project->logActivity('approved_locked', "Project approved and locked by {$this->adminName()}.", $this->adminName());

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'approved_locked', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
        ]);

        $this->notifyLifecycle($project, 'project_approved_locked', 'Project approved & locked', "\"{$project->name}\" was approved and locked by {$this->adminName()}. Ask an Admin to reopen it if changes are needed.");

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project approved and locked');
    }

    public function downloadDelivery(int $id): StreamedResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if (!$project->delivery_file_path && !$project->delivery_google_drive_file_id) {
            abort(404, 'Project delivery is not available yet.');
        }

        return AttachmentStorageService::download(
            $project->delivery_storage_type, $project->delivery_file_path,
            $project->delivery_file_name ?? "{$project->name}-delivery.zip",
            $project->delivery_file_type, $project->delivery_google_drive_file_id, $project->company_id
        );
    }

    // Step 1 of 2 — Admin signs off on the PM's submitted package. This is a
    // purely internal checkpoint: no client/guest is notified yet, and no
    // email address is collected here. See deliverToClient() for step 2,
    // which actually sends it on.
    public function approveDelivery(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())
            ->with('deliverySubmittedBy:id,name')
            ->findOrFail($id);

        // 'approved_locked' (Project Approval Lock) still needs to reach the
        // client — locking freezes PM edits, not Admin's own delivery
        // actions, so it must not block finishing an already-in-flight
        // delivery review.
        if (!in_array($project->status, ['completed', 'approved_locked'])) {
            return ApiResponse::error('Only a completed project can be delivered to the client.', 422);
        }

        if ($project->delivery_status !== 'pending_admin_review' || (!$project->delivery_file_path && !$project->delivery_google_drive_file_id)) {
            return ApiResponse::error('No project delivery is pending admin review.', 422);
        }

        if ($project->delivery_storage_type === 'internal' && !Storage::exists($project->delivery_file_path)) {
            return ApiResponse::error('The submitted delivery file is missing from storage.', 422);
        }

        $project->update([
            'delivery_status'               => 'approved',
            'delivery_approved_at'          => now(),
            'delivery_approved_by_admin_id' => $this->admin()->id,
        ]);

        $project->logActivity('project_delivery_approved', "{$this->adminName()} approved the final project package. It's ready to send to the client.", $this->adminName(), [
            'file_name' => $project->delivery_file_name,
        ]);

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'project_delivery_approved', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
            'new_values' => ['file_name' => $project->delivery_file_name],
        ]);

        if ($project->delivery_submitted_by) {
            NotificationService::send([
                'company_id'         => $project->company_id,
                'recipient_user_id'  => $project->delivery_submitted_by,
                'actor_admin_id'     => $this->admin()->id,
                'module'             => 'project_management',
                'type'               => 'project_delivery_approved',
                'title'              => 'Project delivery approved',
                'message'            => "\"{$project->name}\" was approved by {$this->adminName()} and will be sent to the client.",
                'entity_type'        => 'Project',
                'entity_id'          => $project->id,
                'url'                => "/projects/{$project->id}",
            ]);
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Delivery approved — ready to send to the client');
    }

    // Step 2 of 2 — Admin actually sends the approved package on to the
    // client, once approveDelivery() above has already run. Only now does a
    // client-linked project need a real portal (checked below), and only now
    // does a guest project (no client_id — e.g. a "New Project" invoice paid
    // by someone with no Client record) need the email address collected on
    // the Delivery page, sent via emailGuestDelivery() below.
    public function deliverToClient(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())
            ->with(['client:id,name,user_id,portal_access'])
            ->findOrFail($id);

        if ($project->delivery_status !== 'approved' || (!$project->delivery_file_path && !$project->delivery_google_drive_file_id)) {
            return ApiResponse::error('This delivery has not been approved yet.', 422);
        }

        if ($project->client_id && (!$project->client?->portal_access || !$project->client?->user_id)) {
            return ApiResponse::error('Enable client portal access before sending this delivery.', 422);
        }

        $validated = $project->client_id ? [] : $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Existence is only checkable for an internal file — a Drive file's
        // liveness is verified at the moment it is actually streamed, same
        // as every other Drive-backed download in this app.
        if ($project->delivery_storage_type === 'internal' && !Storage::exists($project->delivery_file_path)) {
            return ApiResponse::error('The delivery file is missing from storage.', 422);
        }

        $project->update([
            'delivery_status' => 'delivered_to_client',
        ]);

        ProjectDeliverySubmission::create([
            'project_id'              => $project->id,
            'file_path'               => $project->delivery_file_path,
            'file_name'               => $project->delivery_file_name,
            'file_type'               => $project->delivery_file_type,
            'file_size'               => $project->delivery_file_size,
            'storage_type'            => $project->delivery_storage_type,
            'google_drive_file_id'    => $project->delivery_google_drive_file_id,
            'google_drive_folder_id'  => $project->delivery_google_drive_folder_id,
            'delivered_by_admin_id'   => $this->admin()->id,
            'delivered_at'            => now(),
        ]);

        $project->logActivity('project_delivered_to_client', "{$this->adminName()} sent the final project package to the client.", $this->adminName(), [
            'file_name' => $project->delivery_file_name,
        ]);

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'project_delivered_to_client', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
            'new_values' => ['file_name' => $project->delivery_file_name],
        ]);

        if ($project->client?->user_id) {
            Notification::create([
                'user_id'        => $project->client->user_id,
                'actor_admin_id' => $this->admin()->id,
                'company_id'     => $project->company_id,
                'module'         => 'client_portal',
                'type'           => 'project_delivered',
                'title'          => 'Project delivered',
                'body'           => "\"{$project->name}\" is ready to download.",
                'entity_type'    => 'Project',
                'entity_id'      => $project->id,
                'url'            => "/client/projects/{$project->id}",
                'data'           => ['project_id' => $project->id, 'link' => "/client/projects/{$project->id}"],
            ]);
        }

        ProjectComment::create([
            'company_id'      => $project->company_id,
            'project_id'      => $project->id,
            'author_admin_id' => $this->admin()->id,
            'body'            => "Final project package delivered to the client.",
            'visibility'      => 'client',
        ]);

        if (!$project->client_id) {
            $this->emailGuestDelivery($project, $validated['email']);
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project delivered to client');
    }

    // Company Admin has no Project Manager to hand delivery review to — this
    // is the direct one-step counterpart to Api\User\ProjectController::
    // submitDelivery() + approveDelivery() above, for a company with no
    // sub-users (or an Admin who simply wants to deliver something
    // themselves): upload the final file and it goes straight to
    // 'delivered_to_client', skipping the pending-review state entirely,
    // since Admin is already the final authority that review step exists to
    // reach. Works for a guest project too (client_id null) — see
    // approveDelivery()'s comment above on the public payment link being that
    // client's delivery channel instead of a portal.
    public function uploadAndDeliver(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())
            ->with(['client:id,name,user_id,portal_access'])
            ->findOrFail($id);

        // Same reasoning as approveDelivery() above — a locked project must
        // still be deliverable by Admin.
        if (!in_array($project->status, ['completed', 'approved_locked'])) {
            return ApiResponse::error('Only a completed project can be delivered to the client.', 422);
        }

        $validated = $request->validate([
            'file'  => ['required', 'file', 'mimes:' . self::DELIVERY_MIMES, 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::DELIVERY_MAX_KB)],
            'email' => [$project->client_id ? 'nullable' : 'required', 'email'],
        ]);

        $file = $validated['file'];

        $stored = AttachmentStorageService::store($file, $project->company_id, 'project_delivery', "project-deliveries/{$project->id}");
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        // Previous deliveries are kept exactly where they are — see
        // ProjectDeliverySubmission below, which needs its OWN copy of the
        // storage location to keep resolving as a history entry — never
        // moved or deleted when a newer delivery replaces the "current"
        // pointer.
        $project->update([
            'delivery_status'                 => 'delivered_to_client',
            'delivery_file_path'              => $stored->filePath,
            'delivery_file_name'              => $stored->originalName,
            'delivery_file_type'              => $stored->mimeType,
            'delivery_file_size'              => $stored->sizeBytes,
            'delivery_storage_type'           => $stored->storageType,
            'delivery_google_drive_file_id'   => $stored->googleDriveFileId,
            'delivery_google_drive_folder_id' => $stored->googleDriveFolderId,
            'delivery_submitted_at'           => now(),
            'delivery_submitted_by'           => null,
            'delivery_approved_at'            => now(),
            'delivery_approved_by_admin_id'   => $this->admin()->id,
        ]);

        ProjectDeliverySubmission::create(array_merge($stored->locationAttributes(), [
            'project_id'            => $project->id,
            'file_name'             => $project->delivery_file_name,
            'file_type'             => $project->delivery_file_type,
            'file_size'             => $project->delivery_file_size,
            'delivered_by_admin_id' => $this->admin()->id,
            'delivered_at'          => now(),
        ]));

        $project->logActivity('project_delivery_approved', "{$this->adminName()} uploaded and delivered the final project package to the client.", $this->adminName(), [
            'file_name' => $project->delivery_file_name,
        ]);

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'project_delivery_approved', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
            'new_values' => ['file_name' => $project->delivery_file_name],
        ]);

        if ($project->client?->user_id) {
            Notification::create([
                'user_id'        => $project->client->user_id,
                'actor_admin_id' => $this->admin()->id,
                'company_id'     => $project->company_id,
                'module'         => 'client_portal',
                'type'           => 'project_delivered',
                'title'          => 'Project delivered',
                'body'           => "\"{$project->name}\" is ready to download.",
                'entity_type'    => 'Project',
                'entity_id'      => $project->id,
                'url'            => "/client/projects/{$project->id}",
                'data'           => ['project_id' => $project->id, 'link' => "/client/projects/{$project->id}"],
            ]);
        }

        ProjectComment::create([
            'company_id'      => $project->company_id,
            'project_id'      => $project->id,
            'author_admin_id' => $this->admin()->id,
            'body'            => "Final project package uploaded and delivered to the client.",
            'visibility'      => 'client',
        ]);

        if (!$project->client_id) {
            $this->emailGuestDelivery($project, $validated['email']);
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project delivered to client');
    }

    // A guest project's only delivery channel is the public payment-link
    // page (Api\PublicInvoiceController::downloadDelivery(), keyed off the
    // invoice's payment_token) — this emails that link to the address the
    // admin entered on the Delivery page. A failed/missing send must never
    // fail the delivery itself, since the file is already stored either way.
    // A guest email larger than this rides a real risk of bouncing off the
    // recipient's own inbound limit (Gmail/Outlook cap around 20-25MB) on
    // top of whatever the sending relay allows — better to fail loud in the
    // log than send-and-silently-bounce.
    private const MAX_EMAIL_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    private function emailGuestDelivery(Project $project, string $email): void
    {
        $companyName = \App\Models\Company::find($project->company_id)?->invoicingProfile()['name'] ?? config('app.name');
        $invoice = $project->invoice;

        try {
            // Preferred path — this project was paid through a public
            // payment link, so its invoice already carries (or can carry) a
            // payment_token, and the client's own pay page can show the
            // Download button (Api\PublicInvoiceController::downloadDelivery()).
            if ($invoice) {
                if (!$invoice->payment_token || ($invoice->token_expires_at && $invoice->token_expires_at->isPast())) {
                    $invoice->generatePublicToken(90);
                    $invoice->refresh();
                }

                $downloadUrl = FrontendUrl::to('pay/invoice/' . $invoice->payment_token);
                Mail::to($email)->send(new ProjectDeliveredMail($project, $downloadUrl, $companyName));
                return;
            }

            // No invoice at all (e.g. a project created without ever going
            // through the payment-link flow) — there is no portal and no
            // pay page to link to, so the only channel left is attaching
            // the file straight to the email. Only possible for an
            // INTERNAL delivery — Mail::attach() needs a real local path
            // (Storage::path()), and a Drive-stored file has none; pulling
            // one down just to re-attach it to an email is disproportionate
            // for what is already a rare, guest-only fallback path (the
            // MAX_EMAIL_ATTACHMENT_BYTES check right below already skips
            // this for any sizeable file regardless).
            if ($project->delivery_storage_type !== 'internal' || !$project->delivery_file_path || !Storage::exists($project->delivery_file_path)) {
                Log::warning('Cannot email guest delivery — no invoice to link to and no attachable delivery file', ['project_id' => $project->id]);
                return;
            }

            if (($project->delivery_file_size ?? 0) > self::MAX_EMAIL_ATTACHMENT_BYTES) {
                Log::warning('Cannot email guest delivery — no invoice to link to and the file is too large to attach', ['project_id' => $project->id, 'file_size' => $project->delivery_file_size]);
                return;
            }

            Mail::to($email)->send(
                (new ProjectDeliveredMail($project, null, $companyName))
                    ->attach(Storage::path($project->delivery_file_path), array_filter([
                        'as'   => $project->delivery_file_name,
                        'mime' => $project->delivery_file_type,
                    ]))
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send ProjectDeliveredMail: ' . $e->getMessage(), ['project_id' => $project->id]);
        }
    }

    // GET /admin/projects/{id}/deliveries — full history of every time this
    // project's final package was delivered (see ProjectDeliverySubmission).
    public function deliveryHistory(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $history = $project->deliverySubmissions()
            ->with('deliveredByAdmin:id,name')
            ->get()
            ->map(fn ($d) => [
                'id'           => $d->id,
                'file_name'    => $d->file_name,
                'file_type'    => $d->file_type,
                'file_size'    => $d->file_size,
                'delivered_at' => $d->delivered_at?->toIso8601String(),
                'delivered_by' => $d->deliveredByAdmin?->name,
            ]);

        return ApiResponse::success($history);
    }

    // GET /admin/projects/{id}/deliveries/{deliveryId}/download — a specific
    // past version, not just the current one (Storage::exists() guards a
    // version whose file was pruned outside the app, e.g. manual disk cleanup).
    public function downloadDeliverySubmission(int $id, int $deliveryId): StreamedResponse|JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $delivery = $project->deliverySubmissions()->findOrFail($deliveryId);

        if ($delivery->storage_type === 'internal' && !Storage::exists($delivery->file_path)) {
            return ApiResponse::error('This delivery file is missing from storage.', 422);
        }

        return AttachmentStorageService::download(
            $delivery->storage_type, $delivery->file_path, $delivery->file_name ?? "{$project->name}-delivery.zip",
            $delivery->file_type, $delivery->google_drive_file_id, $project->company_id
        );
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $validated = $request->validate([
            'force'                  => ['nullable', 'boolean'],
            'reason'                 => ['nullable', 'string', 'max:1000'],
            'confirm_unpaid_invoice' => ['nullable', 'boolean'],
        ]);
        $force = (bool) ($validated['force'] ?? false);

        // A never-activated draft (or still-unpaid placeholder) isn't
        // something to "close" — it's a stub.
        if ($project->isDraft()) {
            return ApiResponse::error("Activate this project before closing it — it is currently {$project->status}.", 422);
        }

        if ($project->status === 'closed') {
            return ApiResponse::error('Project is already closed.', 422);
        }

        if (!in_array($project->status, ['completed', 'approved_locked']) && !$force) {
            $project->logActivity('close_blocked', "Close attempted by {$this->adminName()} but project is not yet Completed.", $this->adminName());
            return ApiResponse::error('Project must be Completed before it can be closed. Use Force Close to close anyway.', 422);
        }

        if ($force && empty($validated['reason'])) {
            return ApiResponse::error('A reason is required to force-close a project that is not yet Completed.', 422);
        }

        if ($this->completionService()->hasUnpaidInvoice($project) && empty($validated['confirm_unpaid_invoice'])) {
            return ApiResponse::error('This project has an unpaid invoice. Confirm to close anyway.', 422, ['warning' => 'unpaid_invoice']);
        }

        $project->update([
            'status'                => 'closed',
            'closed_at'             => now(),
            'closed_by_admin_id'    => $this->admin()->id,
            'close_reason'          => $validated['reason'] ?? null,
            // Closing settles any reopen request that was still pending on
            // a locked project — there's nothing left to reopen into.
            'reopen_requested_at'   => null,
            'reopen_requested_by'   => null,
            'reopen_request_reason' => null,
        ]);

        $project->logActivity(
            'closed',
            "Project closed by {$this->adminName()}." . (!empty($validated['reason']) ? " Reason: {$validated['reason']}" : ''),
            $this->adminName()
        );

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'closed', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
        ]);

        $this->notifyLifecycle($project, 'project_closed', 'Project closed', "\"{$project->name}\" was closed by {$this->adminName()}.");

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project closed');
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if (!in_array($project->status, ['completed', 'approved_locked', 'closed'])) {
            return ApiResponse::error('Only a Completed, Approved & Locked, or Closed project can be reopened.', 422);
        }

        $fromStatus = $project->status;
        $viaRequest = (bool) $project->reopen_requested_at;
        $requestedBy = $project->reopen_requested_by;

        $project->reopen($validated['reason'], $this->admin()->id);

        $project->logActivity('reopened', "Project reopened by {$this->adminName()}. Reason: {$validated['reason']}", $this->adminName(), [
            'from_status' => $fromStatus,
            'via_request' => $viaRequest,
        ]);

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'reopened', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
        ]);

        $this->notifyLifecycle($project, 'project_reopened', 'Project reopened', "\"{$project->name}\" was reopened by {$this->adminName()}. Reason: {$validated['reason']}");

        // The PM who asked for this specifically hears back, distinct from
        // notifyLifecycle()'s broader PM/team notification above.
        if ($requestedBy) {
            Notification::create([
                'user_id'    => $requestedBy,
                'company_id' => $project->company_id,
                'type'       => 'reopen_request_approved',
                'title'      => 'Reopen request approved',
                'body'       => "Your request to reopen \"{$project->name}\" was approved by {$this->adminName()}.",
                'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project reopened');
    }

    // Notifies PM/team/production/seller via the existing per-user Notification
    // channel, plus a visibility='client' system comment if Client Portal is
    // active for this project — the only client-facing channel this codebase
    // has. Company Admin itself is notified via the SystemAuditLog entry
    // written alongside each call site (feeds the Admin notification bell).
    private function notifyLifecycle(Project $project, string $type, string $title, string $body): void
    {
        $service = $this->completionService();
        $targetUserIds = $service->notificationTargetUserIds($project);

        foreach ($targetUserIds as $userId) {
            Notification::create([
                'user_id' => $userId, 'company_id' => $project->company_id,
                'type' => $type, 'title' => $title, 'body' => $body,
                'data' => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);
        }

        // Same internal recipients by email, for the three lifecycle events
        // that matter outside the app (activated/completed/approved) —
        // projectLifecycle() itself ignores every other $type. The CLIENT is
        // deliberately not included: they're served by the client-facing
        // system comment below, plus ProjectActivatedMail on activation.
        EventMailService::projectLifecycle(
            $project, $type, $body,
            collect($targetUserIds)->map(fn ($id) => ['user_id' => $id])->all(),
        );

        if ($service->clientPortalActive($project)) {
            ProjectComment::create([
                'company_id'      => $project->company_id,
                'project_id'      => $project->id,
                'author_admin_id' => $this->admin()->id,
                'body'            => $body,
                'visibility'      => 'client',
            ]);
        }
    }

    /**
     * Merged activity feed for a project: audit-log entries (created/updated/
     * deleted/team_assigned) for the project itself and its tasks, plus any
     * admin/staff-authored comments — sorted newest first.
     */
    public function activity(int $id): JsonResponse
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $taskIds = Task::where('project_id', $project->id)->pluck('id');

        $projectLogs = $project->activities()
            ->get()
            ->map(fn ($activity) => [
                'type'        => 'log',
                'action'      => $activity->type,
                'entity_type' => 'Project',
                'description' => $activity->description,
                'causer_name' => $activity->causer_name,
                'meta'        => $activity->meta,
                'created_at'  => $activity->created_at,
            ]);

        $projectActivityActions = $projectLogs->pluck('action')->all();

        $logs = SystemAuditLog::where(function ($q) use ($id, $taskIds, $projectActivityActions) {
                $q->where(function ($q2) use ($id, $projectActivityActions) {
                    $q2->where(['entity_type' => 'Project', 'entity_id' => $id])
                        ->when($projectActivityActions, fn ($q3) => $q3->whereNotIn('action', $projectActivityActions));
                })
                ->orWhere(function ($q2) use ($taskIds) {
                    $q2->where('entity_type', 'Task')->whereIn('entity_id', $taskIds);
                });
            })
            // Comment-added actions already appear as proper comment bubbles
            // via the $comments query below — exclude here to avoid showing
            // each comment twice in the merged feed.
            ->where('action', 'not like', '%_comment_added')
            ->get()
            ->map(fn ($log) => [
                'type'       => 'log',
                'action'     => $log->action,
                'entity_type' => $log->entity_type,
                'created_at' => $log->created_at,
            ]);

        $comments = ProjectComment::where('project_id', $id)
            // Task-scoped comments belong on their own Task page only —
            // mirrors ProjectCommentController::index()'s scoping so a task's
            // comments don't leak into this project-level History feed.
            ->whereNull('task_id')
            ->with(['authorAdmin:id,name', 'authorUser:id,name'])
            ->get()
            ->map(fn ($c) => [
                'type'       => 'comment',
                'body'       => $c->body,
                'task_id'    => $c->task_id,
                'author'     => $c->authorAdmin?->name ?? $c->authorUser?->name ?? 'Unknown',
                'created_at' => $c->created_at,
            ]);

        $activity = $projectLogs->concat($logs)->concat($comments)->sortByDesc('created_at')->values();

        return ApiResponse::success($activity);
    }

    /**
     * Creates the project's storage folder plus the 10 system subfolders,
     * both as ProjectFolder rows and as real backing directories.
     * Reuses folders if they already exist (safe to retry); cleans up any
     * directories it created if a later step fails, so nothing is orphaned.
     */
    private function createProjectFolders(Project $project): void
    {
        $slug = Str::slug($project->name) ?: 'project';
        $rootPath = "companies/{$project->company_id}/projects/{$project->id}-{$slug}";

        $createdDirs = [];

        try {
            if (!Storage::exists($rootPath)) {
                Storage::makeDirectory($rootPath);
                $createdDirs[] = $rootPath;
            }

            $project->update(['storage_folder' => $rootPath]);

            foreach (self::SYSTEM_FOLDERS as $name) {
                $folderPath = "{$rootPath}/{$name}";

                if (!Storage::exists($folderPath)) {
                    Storage::makeDirectory($folderPath);
                    $createdDirs[] = $folderPath;
                }

                ProjectFolder::firstOrCreate(
                    ['project_id' => $project->id, 'name' => $name, 'parent_folder_id' => null],
                    ['folder_path' => $folderPath, 'is_system' => true, 'created_by' => null]
                );
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($createdDirs) as $dir) {
                Storage::deleteDirectory($dir);
            }
            throw $e;
        }
    }
}
