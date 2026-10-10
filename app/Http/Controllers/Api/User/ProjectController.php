<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyModule;
use App\Models\CompanyUserAssignment;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\ProjectTeamMember;
use App\Models\ProjectComment;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Services\AssignmentMailService;
use App\Services\AttachmentStorageService;
use App\Services\EventMailService;
use App\Services\ProjectChatService;
use App\Services\ProjectCompletionService;
use App\Services\NotificationService;
use App\Services\ProjectSellerAssignmentService;
use App\Support\FrontendUrl;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    use ScopesToActiveCompany;

    private const DELIVERY_MIMES = 'zip,pdf,doc,docx,xls,xlsx,png,jpg,jpeg';
    private const DELIVERY_MAX_KB = 51200;

    // Mirrors Api\Admin\ProjectController::SYSTEM_FOLDERS — every project gets
    // the same starter folder set regardless of who created it.
    private const SYSTEM_FOLDERS = [
        'documents', 'tasks', 'production', 'compliance', 'invoices',
        'client-files', 'internal-notes', 'deliverables', 'revisions', 'timesheets',
    ];

    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey, string $moduleKey = 'project_management'): bool
    {
        $user = $this->user();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', $moduleKey)
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $user->company_id, $user->role_type, $moduleKey, $permKey, $result);
        return $result;
    }

    private function moduleActive(string $dbModuleKey): bool
    {
        return CompanyModule::where('company_id', $this->user()->company_id)
            ->where('module_key', $dbModuleKey)
            ->where('is_enabled', true)
            ->exists();
    }

    private function anyCan(array $permKeys): bool
    {
        foreach ($permKeys as $key) {
            if ($this->can($key)) return true;
        }
        return false;
    }

    private function logActivity(int $companyId, string $action, string $entityType, int $entityId, array $newValues = []): void
    {
        SystemAuditLog::create([
            'company_id' => $companyId, 'user_id' => $this->user()->id,
            'action' => $action, 'module_key' => 'project_management',
            'entity_type' => $entityType, 'entity_id' => $entityId, 'new_values' => $newValues,
        ]);
    }

    // Projects the current staff member can see — purely permission-based, no
    // Data Scope layer: everything in their company if canViewAllCompanyProjects
    // is granted, otherwise only projects where they are the manager, a team
    // member, individually assigned a task, the one who created/handed it
    // off, the seller who initiated its handoff, or — a Seller's own sales
    // activity — the project is linked to a lead or client that's theirs.
    private function visibleProjects()
    {
        $user = $this->user();
        $base = Project::where('company_id', $this->activeCompanyId());

        // Draft (and still-unpaid placeholder) projects are name-only stubs —
        // auto-created either the moment an invoice is raised ('unpaid') or
        // once a client's payment lands (promoted to 'draft') via
        // App\Services\PaymentProjectStartService — and are reserved for
        // whoever can actually act on them — Company Admin (a different guard
        // entirely) or a sub-user granted canActivateProjects. Everyone else
        // never sees one, in any list or by id, until it's activated. Applied
        // before the canViewAllCompanyProjects shortcut below so that broad
        // grant doesn't leak drafts.
        if (!$this->can('canActivateProjects')) {
            $base->whereNotIn('status', ['draft', 'unpaid']);
        }

        if ($this->can('canViewAllCompanyProjects')) {
            return $base;
        }

        // Two tiers of tie, and the difference is what a seller switch acts on.
        //
        // HARD ties are a current, explicit assignment ON THIS PROJECT: its PM,
        // its seller, a team member, a task assignee. These always grant
        // visibility — losing them is what removing someone already means.
        //
        // SOFT ties are inherited rather than assigned: whoever raised the
        // project, or whoever owns the originating lead or the client. They are
        // how a seller sees their own work before anyone staffs it.
        //
        // The problem they caused: switching a project to another seller left
        // the PREVIOUS seller seeing it forever, because the lead is still
        // assigned to them and they usually created the project. The switch
        // looked like it had done nothing. So a soft tie is now suppressed for
        // anyone this project has been switched AWAY from
        // (project_seller_assignments.old_seller_id — the switch ledger that
        // ProjectSellerAssignmentService already writes on every change).
        //
        // Scoped deliberately: only a PAST SELLER of THIS project loses the
        // soft tier, and only for this project. Nobody else's visibility moves,
        // and a past seller who is still the PM, still on the team, or still
        // holds a task keeps full access through the hard tier above — which is
        // also why being removed from the team is what finally hides it.
        return $base->where(function ($q) use ($user) {
            $q->where('project_manager_id', $user->id)
              ->orWhere('seller_id', $user->id)
              ->orWhereHas('teamMembers', fn($t) => $t->where('user_id', $user->id))
              ->orWhereHas('tasks', fn($t) => $t->where('assigned_to', $user->id))
              ->orWhere(function ($soft) use ($user) {
                  $soft->where(function ($inherited) use ($user) {
                      $inherited->where('created_by', $user->id)
                          ->orWhereHas('lead', fn($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                          ->orWhereHas('client', fn($c) => $c->where('account_manager', $user->id));
                  })->whereDoesntHave('sellerAssignments', fn($a) => $a->where('old_seller_id', $user->id));
              });
        });
    }

    public function index(Request $request): JsonResponse
    {
        // The Project Dashboard page is gated on canViewProjectDashboard alone
        // (a summary view) but pulls its data from this same list endpoint —
        // accept either permission so a dashboard-only grant isn't left with a
        // sidebar link that 403s the moment it loads. canViewLinkedProjects is
        // the Seller-tier equivalent of canViewProjects — same list endpoint,
        // visibleProjects() above naturally narrows it to their own linked work.
        // canViewTeamResources/canAssignTeamResources cover the Team/Resources
        // page, which reuses this same endpoint for its per-project member
        // lists (frontend/app/projects/team/page.tsx) — same reasoning.
        if (!$this->can('canViewProjects') && !$this->can('canViewProjectDashboard') && !$this->can('canViewLinkedProjects')
            && !$this->can('canViewTeamResources') && !$this->can('canAssignTeamResources') && !$this->can('canViewAllCompanyProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        // lead:id,name / invoice:id,customer_name — same fallback as
        // Api\Admin\ProjectController::index(): a guest project (no
        // client_id) has no `client` relation to read a name from.
        $q = $this->visibleProjects()->with(['company:id,name', 'client:id,name', 'lead:id,name', 'invoice:id,customer_name', 'projectManager:id,name,role_type,custom_role_label', 'seller:id,name,role_type,custom_role_label', 'teamMembers.user:id,name,role_type,custom_role_label']);

        if ($request->filled('status'))    $q->where('status', $request->status);
        if ($request->filled('client_id')) $q->where('client_id', $request->client_id);

        $projects = $q->orderByDesc('created_at')->get();

        $taskStats = Task::whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as done')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $user = $this->user();
        $canViewAll = $this->can('canViewAllCompanyProjects');

        $projects = $projects->map(function ($p) use ($taskStats, $user, $canViewAll) {
            $stats = $taskStats[$p->id] ?? null;
            // A completed/closed project reads 100% regardless of its task
            // ratio — otherwise one with zero tasks (or any tasks still open
            // at completion time) shows 0%/partial forever despite the work
            // being done.
            $p->progress = in_array($p->status, ['completed', 'closed'], true)
                ? 100
                : ($stats && $stats->total > 0 ? round(($stats->done / $stats->total) * 100) : 0);
            // The viewing user's own relationship to THIS project — the frontend's
            // "Role" column showed the assigned PM's name (wrong: someone else's
            // identity under a header that reads as "your role"). This reflects
            // why visibleProjects() actually surfaced the project to them.
            //
            // seller_id is checked BEFORE project_manager_id/teamRole: a Seller
            // handing off their own project defaults to being its PM (see
            // store()'s handoff branch — they get project_manager_id set to
            // themselves when they don't hold canRequestPMAssignment), but
            // that's an artifact of the handoff, not a real PM appointment —
            // showing "Project Manager" there is misleading, since the person
            // is a Seller by role, just acting as caretaker of their own
            // handed-off project.
            $teamRole = $p->teamMembers->firstWhere('user_id', $user->id)?->role_in_project;
            $p->my_role = match (true) {
                $p->seller_id === $user->id   => 'Seller',
                $p->project_manager_id === $user->id, $teamRole === 'project_manager' => 'Project Manager',
                $teamRole !== null => 'Team Member',
                $p->created_by === $user->id  => 'Creator',
                $p->tasks()->where('assigned_to', $user->id)->exists() => 'Assigned',
                $canViewAll => 'Company-wide Access',
                default => '—',
            };

            return $p;
        });

        return ApiResponse::success($projects);
    }

    // canCreateProjects previously had no effect — sub-users could be granted
    // it, but there was no route or form to actually create a project (only
    // Company Admin could). This gives it a real, gated implementation.
    //
    // A Seller with ONLY canCreateProjectHandoff (not the broader
    // canCreateProjects) can create a project too, but strictly as a handoff:
    // it requires both the Sales ('leads') and Project Management ('projects')
    // DB modules active, and EITHER a Won lead of theirs (lead_id) OR a client
    // of theirs (client_id) — not a general "create any project" right. Such a
    // project is tagged seller_id/source='sales_handoff' (see below) and can't
    // freely name a project_manager_id unless canRequestPMAssignment is also
    // held — that stays a Project Manager-level action otherwise.
    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        // A Seller holding canCreateProjects (granted via the "Manage
        // Projects" bundle) gets the same unrestricted "create any project,
        // no lead/payment needed" path as PM/Manager tiers. Separately, a
        // Seller who only holds canCreateProjectHandoff still goes through
        // the Deal-eligibility-gated $isHandoff branch below.
        $canFullCreate = $this->can('canCreateProjects');
        $canHandoff    = $this->can('canCreateProjectHandoff');

        if (!$canFullCreate && !$canHandoff) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $user->company_id;
        $isHandoff = !$canFullCreate;
        // Set only when this handoff is triggered from a paid invoice (as
        // opposed to the original Won-lead/own-client triggers below, which
        // stay completely unchanged) — used after project creation to
        // back-link project.invoice_id / invoice.project_id.
        $sourceInvoice  = null;
        $overrideReason = null;

        if ($isHandoff) {
            if (!$this->moduleActive('leads') || !$this->moduleActive('projects')) {
                return ApiResponse::error('Both Sales and Project Management must be active to hand off a project.', 403);
            }

            $sourceInvoiceId = $request->input('source_invoice_id');
            $leadId   = $request->input('lead_id');
            $clientId = $request->input('client_id');
            $canAllLeads        = $this->can('canViewAllCompanyLeads', 'sales');
            $canOverridePayment = $this->can('canOverrideProjectCreationBeforePayment');
            $canManageInvoices  = $this->can('canManageProjectInvoices');

            if ($sourceInvoiceId) {
                $sourceInvoice = Invoice::where('company_id', $companyId)->where('id', $sourceInvoiceId)->first();
                if (!$sourceInvoice) {
                    return ApiResponse::error('Invoice not found.', 404);
                }
                if ($sourceInvoice->status !== 'paid') {
                    // A company that allows partial-payment project start
                    // (the same CompanyDealSettings toggle the Won-lead path
                    // below already reads) also unlocks a handoff from a
                    // Partially Paid invoice with some confirmed payment —
                    // previously this path was strict paid-only, silently
                    // rejecting the same partial payment the lead-based path
                    // would already accept.
                    $dealSettings = \App\Models\CompanyDealSettings::forCompany($companyId);
                    $partiallyEligible = $sourceInvoice->status === 'partially_paid'
                        && $dealSettings->startsOnPartialPayment()
                        && (float) $sourceInvoice->paid_amount > 0;

                    if (!$partiallyEligible && !$canOverridePayment) {
                        $message = $sourceInvoice->status === 'partially_paid'
                            ? 'Project can be created after the required deposit payment is completed.'
                            : 'Project can be created after payment is received.';
                        return ApiResponse::error($message, 422);
                    }
                }
                if ($sourceInvoice->created_by !== $user->id && !$canAllLeads && !$canOverridePayment) {
                    return ApiResponse::error('You do not have permission to create project from this invoice.', 403);
                }
                if ($sourceInvoice->project_id && !$canManageInvoices) {
                    return ApiResponse::error('This invoice is already linked to a project.', 422);
                }
                // A paid invoice (already ownership/permission-checked above)
                // is itself sufficient authorization — feed its own lead_id/
                // client_id into the standard validation below rather than
                // re-running the Won-lead/account-manager checks meant for
                // the other two trigger paths.
                $leadId   = $sourceInvoice->lead_id;
                $clientId = $sourceInvoice->client_id;
                $request->merge(['lead_id' => $leadId, 'client_id' => $clientId]);
            } elseif ($leadId) {
                $leadQuery = Lead::where('company_id', $companyId)->where('id', $leadId);
                if (!$canAllLeads) {
                    $leadQuery->where(fn($q) => $q->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id));
                }
                $lead = $leadQuery->first();
                if (!$lead) {
                    return ApiResponse::error('You can only hand off a project from your own lead.', 403);
                }
                if ($lead->status !== 'won') {
                    return ApiResponse::error('A project can only be handed off from a Won lead.', 422);
                }

                // A Won lead has usually already been converted to a Client by
                // this point (Api\User\LeadController::convert()) — without
                // this, the resulting project would carry lead_id but a null
                // client_id, making it permanently invisible to the Client
                // Portal (Api\Client\ProjectController filters strictly on
                // client_id, no lead_id fallback). Only fills it in when the
                // request didn't already send one.
                if (!$clientId && $lead->client) {
                    $clientId = $lead->client->id;
                    $request->merge(['client_id' => $clientId]);
                }

                // Duplicate-project prevention — a Deal gets at most one
                // handoff project. A second attempt (double-click, retry)
                // returns the existing project instead of erroring the
                // seller into creating a second one.
                $existingProject = $lead->projects()->first();
                if ($existingProject) {
                    return ApiResponse::success(
                        $existingProject,
                        "Project {$existingProject->reference} has already been created for this Deal.",
                        200
                    );
                }

                // Core rule: marking a Lead Won never by itself makes it
                // eligible for a project — the Deal must have cleared its
                // kickoff-payment requirement first (see
                // App\Services\DealEligibilityService). A company that has
                // enabled "allow partial-payment project start" only
                // requires SOME verified payment, not the full amount.
                $dealSettings = \App\Models\CompanyDealSettings::forCompany($companyId);
                $eligible = $dealSettings->startsOnPartialPayment()
                    ? \App\Services\DealEligibilityService::netPaidAmount($lead) > 0
                    : \App\Services\DealEligibilityService::isEligible($lead);

                if (!$eligible) {
                    if (!$canOverridePayment || !$dealSettings->allow_admin_override) {
                        return ApiResponse::error('The Deal has not received the required kickoff payment.', 422);
                    }

                    $overrideReason = trim((string) $request->input('override_reason'));
                    if ($overrideReason === '') {
                        return ApiResponse::error('An override reason is required to create this project before payment.', 422);
                    }
                }
            } elseif ($clientId) {
                $clientQuery = \App\Models\Client::where('company_id', $companyId)->where('id', $clientId);
                if (!$canAllLeads) {
                    $clientQuery->where('account_manager', $user->id);
                }
                if (!$clientQuery->exists()) {
                    return ApiResponse::error('You can only create a project handoff for your own client.', 403);
                }
            } else {
                return ApiResponse::error('A Won lead_id, your own client_id, or a paid source_invoice_id is required to hand off a project.', 422);
            }
        }

        $validated = $request->validate([
            'client_id'          => ['nullable', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            // Sales → Project handoff: links this project back to the won
            // lead it came from, so Sales can show "Linked Projects".
            'lead_id'            => ['nullable', 'integer', Rule::exists('leads', 'id')->where('company_id', $companyId)],
            'project_manager_id' => ['nullable', 'integer'],
            'name'               => ['required', 'string', 'max:255'],
            // max is a guard on the `text` column's own 65KB ceiling, not a
            // product rule — the description field is markup-formatted now
            // (see frontend/components/ui/RichTextField.tsx), so a pasted
            // document could otherwise overflow the column outright.
            'description'        => ['nullable', 'string', 'max:20000'],
            'status'             => ['nullable', 'in:planning,active,on_hold,completed,cancelled'],
            'priority'           => ['nullable', 'in:low,medium,high,urgent'],
            'deadline'           => ['nullable', 'date'],
            'budget'             => ['nullable', 'numeric', 'min:0'],
        ]);

        // Naming a project_manager_id is a PM-level action for a handoff-only
        // Seller — requires the explicit "Request PM Assignment" permission,
        // otherwise it's silently dropped (they still end up as PM themselves
        // via the default below, same as before).
        if ($isHandoff && !$this->can('canRequestPMAssignment')) {
            unset($validated['project_manager_id']);
        }

        // A plain company_id Rule::exists() would wrongly reject a
        // multi-company user whose raw company_id column points at their
        // OTHER company — ofCompany() also accepts an active
        // company_user_assignments row for this one.
        if (!empty($validated['project_manager_id']) && !User::ofCompany($companyId)->where('id', $validated['project_manager_id'])->exists()) {
            return ApiResponse::error('The selected project manager is invalid.', 422);
        }

        $validated['company_id'] = $companyId;
        $validated['status']   ??= 'active';
        $validated['priority'] ??= 'medium';
        // The project's Start Date is always the moment it was created —
        // never a separately chosen date.
        $validated['start_date'] = now()->toDateString();
        $validated['created_by'] = $user->id;
        $validated['reference']  = $this->nextProjectReference();
        // seller_id is set for any Seller-created project (handoff or a
        // full, unrestricted create) — source is only meaningful for the
        // handoff path, where it records what triggered the project.
        if ($user->role_type === 'seller') {
            $validated['seller_id'] = $user->id;
        }
        if ($isHandoff) {
            $validated['source'] = $sourceInvoice
                ? ($sourceInvoice->status === 'paid' ? 'paid_invoice_handoff' : 'partial_paid_invoice_handoff')
                : 'sales_handoff';
        }
        if ($sourceInvoice) {
            $validated['invoice_id'] = $sourceInvoice->id;
        }
        // Default the creator to project manager unless they named someone else.
        $validated['project_manager_id'] ??= $user->id;

        $project = DB::transaction(function () use ($validated, $user) {
            $project = Project::create($validated);
            $this->createProjectFolders($project, $user->id);
            return $project;
        });

        // Complete the back-link: the invoice now knows which project it's
        // billed under, same as any additional invoice linked later via the
        // dedicated link-invoice endpoint.
        if ($sourceInvoice) {
            $sourceInvoice->update(['project_id' => $project->id]);
        }

        // Whoever ends up as project_manager_id must also have a
        // ProjectTeamMember row, or visibleProjects()'s guard scope never
        // actually grants them access via the team-membership leg.
        //
        // holdingRole(): a self-handoff Seller's cosmetic PM-seat placeholder
        // (project_manager_id defaulted to themselves above) belongs in Sales
        // Team Resources, same as every other row of theirs — never
        // Production, which is where the 'team' column's bare default
        // ('production') would otherwise misfile them. Mirrors the same
        // tagging in this controller's own assignTeam().
        $pmIsSeller = User::where('id', $project->project_manager_id)->holdingRole($project->company_id, 'seller')->exists();
        ProjectTeamMember::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $project->project_manager_id],
            ['role_in_project' => 'project_manager', 'team' => $pmIsSeller ? 'sales' : 'production', 'assigned_by' => $user->id]
        );

        // Every project must have a Compliance Case — never allowed to block
        // or duplicate project creation, see ComplianceCaseService.
        try {
            \App\Services\ComplianceCaseService::ensureCaseForProject($project);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[compliance] project ' . $project->id . ' create: ' . $e->getMessage());
        }

        if ($project->project_manager_id !== $user->id) {
            Notification::create([
                'user_id'    => $project->project_manager_id,
                'company_id' => $project->company_id,
                'type'       => 'project_assigned',
                'title'      => 'New project assigned',
                'body'       => "You were assigned as project manager for \"{$project->name}\".",
                'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);

            // Email alongside the bell notification — non-blocking, see
            // AssignmentMailService. The enclosing !== $user->id guard already
            // covers the "creator is their own PM" default.
            AssignmentMailService::projectManagerAssigned($project, $project->project_manager_id, $user->name, $user->id);
        }

        // A Seller creating their own project — handoff OR a full,
        // unrestricted create (canCreateProjects) — is dropped straight into
        // its chat — same reasoning as ProjectSellerAssignmentService::assign().
        // Without this they'd hold canViewProjectChat/canSendProjectChatMessage
        // but still 403 on their own project until a PM/Admin separately
        // opened "Manage Participants" — see ProjectChatService::addSeller().
        // Keyed off role_type, not $isHandoff, so it fires on EVERY
        // Seller-created project (matches the seller_id assignment above,
        // which uses the same condition) — a Seller holding the default
        // canCreateProjects grant almost always takes the non-handoff path,
        // which this used to skip entirely.
        if ($user->role_type === 'seller') {
            ProjectChatService::addSeller($project, $user->id);
        }

        $this->logActivity($project->company_id, 'created', 'Project', $project->id, $validated);
        $project->logActivity('created', "Project \"{$project->name}\" created by {$user->name}.", $user->name, [
            'created_by' => $user->id,
        ]);

        NotificationService::notifyCompanyAdmins($project->company_id, null, [
            'actor_user_id' => $user->id,
            'module'        => 'project_management',
            'type'          => 'project_created_by_pm',
            'title'         => 'New project created',
            'message'       => "{$user->name} created project \"{$project->name}\".",
            'entity_type'   => 'Project',
            'entity_id'     => $project->id,
            'url'           => "/admin/projects/{$project->id}",
        ]);

        if ($project->project_manager_id) {
            $managerName = User::find($project->project_manager_id)?->name ?? 'Unknown';
            $project->logActivity('manager_assigned', "{$user->name} assigned {$managerName} as project manager.", $user->name, [
                'to' => $project->project_manager_id,
            ]);
        }

        if ($project->lead_id) {
            $lead = \App\Models\Lead::find($project->lead_id);
            if ($lead) {
                $lead->logActivity('note_added', "Project \"{$project->name}\" created from this lead", $user->name ?? 'User');
                SystemAuditLog::create([
                    'company_id'  => $project->company_id,
                    'user_id'     => $user->id,
                    'action'      => 'project_handoff_created',
                    'module_key'  => 'sales',
                    'entity_type' => 'Lead',
                    'entity_id'   => $lead->id,
                    'new_values'  => ['preview' => "Project \"{$project->name}\" created from lead \"{$lead->name}\"", 'author' => $user->name],
                ]);

                // Deal fulfillment_status flips to project_created now that
                // lead->projects()->exists() is true.
                \App\Services\DealEligibilityService::recomputeFulfillmentStatus($lead);

                if ($overrideReason !== null) {
                    $lead->logActivity('admin_override_used',
                        "Project created before required payment — override reason: {$overrideReason}",
                        $user->name ?? 'User', ['override_reason' => $overrideReason, 'overridden_by' => $user->id]);
                    SystemAuditLog::create([
                        'company_id'  => $project->company_id,
                        'user_id'     => $user->id,
                        'action'      => 'project_creation_override',
                        'module_key'  => 'project_management',
                        'entity_type' => 'Project',
                        'entity_id'   => $project->id,
                        'new_values'  => [
                            'preview' => "Project \"{$project->name}\" created before required payment",
                            'override_reason' => $overrideReason,
                            'payment_amount_at_override' => \App\Services\DealEligibilityService::netPaidAmount($lead),
                        ],
                    ]);
                }

                // Client-facing activity trail — mirrors
                // InvoicePaymentService::logClientActivity()'s exact shape.
                if ($lead->client_id) {
                    SystemAuditLog::create([
                        'company_id'  => $project->company_id,
                        'user_id'     => null,
                        'action'      => 'client_project_activated',
                        'module_key'  => 'client',
                        'entity_type' => 'Client',
                        'entity_id'   => $lead->client_id,
                        'new_values'  => ['preview' => "Project \"{$project->name}\" ({$project->reference}) has been activated", 'project_id' => $project->id],
                    ]);
                }
            }
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project created', 201);
    }

    // Next PRJ-{year}-{seq} reference — same generator pattern as
    // Api\User\LeadController's deal_reference / InvoiceController's
    // invoice_number.
    // withTrashed() on BOTH queries is essential, not defensive: Project is
    // soft-deleting, so a deleted project's row — and its reference — stays in
    // the table behind projects_reference_unique. Without it the uniqueness
    // probe reports "free" for a reference the index still holds, the loop exits
    // on the first candidate, and Project::create() dies on a duplicate key —
    // i.e. project creation broke permanently once any project was deleted.
    private function nextProjectReference(): string
    {
        $year = now()->year;
        $last = Project::withTrashed()
            ->whereYear('created_at', $year)
            ->where('reference', 'like', "PRJ-{$year}-%")
            ->latest('id')
            ->value('reference');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        do {
            $reference = sprintf('PRJ-%d-%04d', $year, $seq++);
        } while (Project::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    // POST /user/projects/{id}/invoices/link — attach an EXISTING invoice
    // (deposit/milestone/final/change-request) to a project that already
    // exists, distinct from the store()-time paid-invoice handoff above.
    public function linkInvoice(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageProjectInvoices')) {
            return ApiResponse::error('You do not have permission to link invoices to this project.', 403);
        }

        $user = $this->user();
        $project = $this->visibleProjects()->findOrFail($id);

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer'],
        ]);

        $invoice = Invoice::where('company_id', $user->company_id)->find($validated['invoice_id']);
        if (!$invoice) {
            return ApiResponse::error('Invoice not found.', 404);
        }
        if ($invoice->project_id && $invoice->project_id !== $project->id) {
            return ApiResponse::error('This invoice is already linked to another project.', 422);
        }

        $invoice->update(['project_id' => $project->id]);
        $this->logActivity($project->company_id, 'invoice_linked', 'Project', $project->id,
            ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number]);

        return ApiResponse::success(null, 'Invoice linked to project');
    }

    // DELETE /user/projects/{id}/invoices/{invoiceId} — unlink.
    public function unlinkInvoice(int $id, int $invoiceId): JsonResponse
    {
        if (!$this->can('canManageProjectInvoices')) {
            return ApiResponse::error('You do not have permission to unlink invoices from this project.', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $invoice = Invoice::where('company_id', $this->user()->company_id)
            ->where('project_id', $project->id)
            ->find($invoiceId);

        if (!$invoice) {
            return ApiResponse::error('Invoice not found on this project.', 404);
        }

        $invoice->update(['project_id' => null]);

        return ApiResponse::success(null, 'Invoice unlinked from project');
    }

    // POST /user/projects/{id}/invoices — create a brand-new invoice already
    // linked to this project ("Create Invoice for this Project"). Mirrors
    // Api\User\InvoiceController::store()'s validation/creation shape,
    // narrowed to what a project-billing invoice needs, plus project_id.
    public function createInvoice(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageProjectInvoices')) {
            return ApiResponse::error('You do not have permission to create invoices for this project.', 403);
        }
        if (!$this->moduleActive('invoices')) {
            return ApiResponse::error('The Invoice module must be active to create project invoices.', 403);
        }

        $user = $this->user();
        $project = $this->visibleProjects()->findOrFail($id);

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
        // terms even though Api\User\InvoiceController::store() (the
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
        $last   = Invoice::whereYear('created_at', $year)->where('invoice_number', 'like', "{$prefix}-{$year}-%")->latest('id')->value('invoice_number');
        $seq    = $last ? ((int) substr($last, -4)) + 1 : 1;
        do {
            $number = sprintf('%s-%d-%04d', $prefix, $year, $seq++);
        } while (Invoice::where('invoice_number', $number)->exists());

        // A milestone invoice must match whatever currency this project has
        // already been invoiced in — never a hardcoded default — so its
        // amounts (and, transitively, paid_amount, which carries no currency
        // of its own) read consistently against every other invoice on this
        // project. A project with no prior invoice at all falls back to
        // Company Admin's own configured currency (see
        // Company::invoicingProfile()), then the request's own currency,
        // then USD as the last resort.
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

        $invoice = Invoice::create([
            'company_id'      => $project->company_id,
            'client_id'       => $project->client_id,
            'lead_id'         => $project->lead_id,
            'project_id'      => $project->id,
            'created_by'      => $user->id,
            'invoice_number'  => $number,
            'subtotal'        => $subtotal,
            'tax_rate'        => $taxRate,
            'tax_amount'      => $taxAmt,
            'discount_amount' => $discount,
            'total_amount'    => $subtotal + $taxAmt - $discount,
            'paid_amount'     => 0,
            'currency'        => $existingCurrency ?? $data['currency'] ?? 'USD',
            'status'          => 'draft',
            'due_date'        => $dueDate,
            'notes'           => $data['notes']           ?? null,
            'invoice_purpose' => $data['invoice_purpose'] ?? null,
            // Recorded only for a no-client project — a client-linked
            // invoice always resolves its recipient from client.email
            // instead, same as everywhere else in the app.
            'customer_name'   => $project->client_id ? null : $project->name,
            'customer_email'  => $project->client_id ? null : $data['recipient_email'],
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

        $this->logActivity($project->company_id, 'invoice_created', 'Project', $project->id,
            ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number]);

        // Send it right away — the project's own client if there is one,
        // else the one-off address collected above. Mirrors
        // Api\User\InvoiceController::sendEmail() exactly (public token,
        // payment URL, InvoiceMail, draft->sent, portal notification), just
        // inlined here since this invoice is always a fresh draft — there is
        // no "already sent" case to check for.
        $recipientEmail = $project->client?->email ?? $data['recipient_email'];

        $company     = \App\Models\Company::find($project->company_id);
        $invoice->generatePublicToken(30);
        $invoice->refresh();
        $paymentUrl  = FrontendUrl::to('pay/invoice/' . $invoice->payment_token);
        $companyName = $company->invoicingProfile()['name'];

        try {
            \Illuminate\Support\Facades\Mail::to($recipientEmail)->send(new \App\Mail\InvoiceMail($invoice, $paymentUrl, $companyName));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[project-invoice] email send failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            // The public token was already generated above, so the link is
            // still valid even though the email itself failed — hand it back
            // so the seller can share it manually instead of losing it.
            $responseData = $invoice->load('items')->toArray();
            $responseData['payment_url'] = $paymentUrl;
            return ApiResponse::success($responseData, 'Invoice created, but the email could not be sent. You can copy the payment link below or resend it from the invoice page.', 201);
        }

        $invoice->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $user->id]);
        \App\Services\InvoiceNotificationService::notifyClientInvoiceSent($invoice);

        if ($invoice->lead_id) {
            Lead::find($invoice->lead_id)?->logActivity('note_added',
                "Invoice {$invoice->invoice_number} sent to {$recipientEmail}", $user->name ?? 'User');
        }
        $this->logActivity($project->company_id, 'invoice_sent', 'Invoice', $invoice->id,
            ['invoice_number' => $invoice->invoice_number, 'sent_to' => $recipientEmail]);

        $responseData = $invoice->load('items')->toArray();
        $responseData['payment_url'] = $paymentUrl;
        $responseData['sent_to']     = $recipientEmail;

        return ApiResponse::success($responseData, "Invoice created and sent to {$recipientEmail}", 201);
    }

    // Mirrors Api\Admin\ProjectController::createProjectFolders() — same
    // starter folder layout, just attributed to the creating staff member
    // instead of null (Company Admin isn't a `users` row so has no id here).
    private function createProjectFolders(Project $project, int $createdByUserId): void
    {
        $slug     = Str::slug($project->name) ?: 'project';
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
                    ['folder_path' => $folderPath, 'is_system' => true, 'created_by' => $createdByUserId]
                );
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($createdDirs) as $dir) {
                Storage::deleteDirectory($dir);
            }
            throw $e;
        }
    }

    // Full relation set + computed progress/billing_summary, respecting the
    // current sub-user's own view permissions — the single source of truth
    // for what a Project API response looks like, used by show() AND every
    // lifecycle action (create/update/activate/complete/close/reopen).
    // Before this, those actions returned a bare or partially-loaded
    // $project->fresh(), so the frontend's onUpdated(updated) =>
    // setProject(updated) wiped out created_by_admin/client/seller/tasks/etc
    // from the page's state, and reset progress to its raw (never-persisted)
    // column value, until the next full reload.
    /**
     * The Sales Team Resources rows $viewer is allowed to SEE on $project.
     *
     * Mirrors getTeamGroups() in frontend/lib/teamResourceGroups.ts, and is
     * the authoritative copy: the payload carries the result as
     * `visible_sales_members` so hiding rows is no longer something only the
     * browser does. The tiers follow the same lineage already used to decide
     * who may ADD (see assignTeam()), read off project_team_members.assigned_by:
     *
     *   literal PM / primary seller  -> the whole Sales roster;
     *   first-level secondary seller -> the Sales roster MINUS the PM's own
     *                                   row (a PM who also holds Seller is
     *                                   tagged 'sales', but is this roster's
     *                                   owner, not one of its resources);
     *   lower-level seller           -> only themselves and the person who
     *                                   added them.
     *
     * The primary seller never appears at all — they are the project's seller,
     * not one of their own secondary sellers, which is why they are filtered
     * out before any tier is applied (same rule as assignTeam()'s refusal to
     * create a team row for them).
     *
     * A seller with no team row here (a past seller, the creator, a task
     * assignee — visibleProjects() is deliberately broad) is treated as
     * first-level: they manage nothing either way, and narrowing them further
     * would blank a roster they can legitimately read today.
     *
     * @return \Illuminate\Support\Collection<int, ProjectTeamMember>
     */
    private function visibleSalesMembers(Project $project, ?User $viewer)
    {
        $primarySellerId = (int) $project->seller_id;
        $pmId            = (int) $project->project_manager_id;

        $sales = $project->teamMembers
            ->reject(fn ($m) => $primarySellerId && (int) $m->user_id === $primarySellerId)
            // The role_type fallback only matters for a row written before the
            // `team` column existed (see the 2026_09_28 backfill migration).
            ->filter(fn ($m) => $m->team === 'sales' || (!$m->team && $m->user?->role_type === 'seller'))
            ->values();

        if (!$viewer) {
            return $sales;
        }

        $viewerId = (int) $viewer->id;
        if ($viewerId === $pmId || $viewerId === $primarySellerId) {
            return $sales;
        }

        $viewerHoldsSeller = User::where('id', $viewerId)
            ->holdingRole($project->company_id, 'seller')->exists();
        if (!$viewerHoldsSeller) {
            // Production-side actors never render this list (canSeeSales is
            // false for them); leaving it whole keeps the payload honest for
            // the PM-ish roles that do.
            return $sales;
        }

        $ownRow = $project->teamMembers->firstWhere('user_id', $viewerId);
        $addedByAuthority = !$ownRow
            || $ownRow->assigned_by === null
            || in_array((int) $ownRow->assigned_by, array_values(array_filter([$pmId, $primarySellerId])), true);

        if ($addedByAuthority) {
            return $sales->reject(fn ($m) => $pmId && (int) $m->user_id === $pmId)->values();
        }

        $keep = [$viewerId, (int) $ownRow->assigned_by];

        return $sales->filter(fn ($m) => in_array((int) $m->user_id, $keep, true))->values();
    }

    private function presentProject(Project $project): Project
    {
        $project->load([
            'client:id,name,email',
            // A project handed off before its Lead converted to a Client has
            // no client_id yet — the frontend falls back to this, then to
            // invoice.customer_name for a fully guest (no Lead either) project.
            'lead:id,name',
            'invoice:id,customer_email,customer_name',
            'projectManager:id,name,role_type,custom_role_label',
            'seller:id,name,email',
            'createdBy:id,name',
            'createdByAdmin:id,name',
            'deliverySubmittedBy:id,name',
            'deliveryApprovedByAdmin:id,name',
            'completionApprovedByAdmin:id,name',
            'reopenRequestedBy:id,name',
            'teamMembers.user:id,name,role_type,custom_role_label',
            'folders' => fn($q) => $q->whereNull('parent_folder_id'),
        ]);

        // Task data is only ever surfaced to actors with real Task visibility
        // (canViewTasks) — a Seller (canViewLinkedProjects only) must never
        // see any task via this project payload, same as the Tasks tab being
        // fully hidden for them on the frontend.
        if ($this->can('canViewTasks')) {
            $project->load(['tasks' => fn($q) => $q->with(['assignedTo:id,name', 'assignedBy:id,name'])]);
        } else {
            $project->setRelation('tasks', collect());
        }

        // Sales Team Resources, already narrowed to this viewer's own tier of
        // the Sales hierarchy — see visibleSalesMembers(). Carried ALONGSIDE
        // team_members rather than filtering it: the Projects list reads the
        // PM's row straight out of team_members to render its "Manager"
        // column (frontend/app/projects/page.tsx), and the timesheets and
        // task pickers read it for their own rosters, so narrowing that array
        // would blank unrelated screens for every Seller.
        $project->visible_sales_members = $this->visibleSalesMembers($project, $this->user());

        $totalTasks = $project->tasks->count();
        $doneTasks  = $project->tasks->where('status', 'completed')->count();
        // A completed/closed project reads 100% regardless of its task
        // ratio — see the same override in index() above.
        $project->progress = in_array($project->status, ['completed', 'closed'], true)
            ? 100
            : ($totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0);

        // Invoices billed under this project + a running billing summary —
        // gated behind canManageProjectInvoices (folds in "View Project
        // Invoices"/"View Project Billing Summary" per the consolidated
        // permission design). A Seller without it still sees the project
        // itself, just not this financial block.
        if ($this->can('canManageProjectInvoices')) {
            $project->load(['invoices' => fn($q) => $q->select(
                'id', 'invoice_number', 'subtotal', 'tax_amount', 'total_amount', 'paid_amount', 'status', 'due_date', 'currency', 'project_id'
            )->orderByDesc('created_at')]);

            $totalInvoiced = (float) $project->invoices->sum('total_amount');
            $totalPaid     = (float) $project->invoices->sum('paid_amount');
            $project->billing_summary = [
                'total_subtotal' => (float) $project->invoices->sum('subtotal'),
                'total_tax'      => (float) $project->invoices->sum('tax_amount'),
                'total_invoiced' => $totalInvoiced,
                'total_paid'     => $totalPaid,
                'outstanding'    => max(0, round($totalInvoiced - $totalPaid, 2)),
            ];
        } else {
            $project->setRelation('invoices', collect());
        }

        return $project;
    }

    public function show(int $id): JsonResponse
    {
        if (!$this->can('canViewProjects') && !$this->can('canViewLinkedProjects') && !$this->can('canViewProjectDashboard')
            && !$this->can('canViewTeamResources') && !$this->can('canAssignTeamResources') && !$this->can('canViewAllCompanyProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);

        return ApiResponse::success($this->presentProject($project));
    }

    public function submitDelivery(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canCompleteProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->with('teamMembers.user:id,name,role_type,custom_role_label', 'company')->findOrFail($id);
        $user = $this->user();

        $isActualPm = $project->project_manager_id === $user->id && $user->role_type === 'project_manager';
        $isTeamPm = $project->teamMembers
            ->contains(fn ($member) => (int) $member->user_id === (int) $user->id && $member->user?->role_type === 'project_manager');

        if (!$isActualPm && !$isTeamPm) {
            return ApiResponse::error('Only the assigned Project Manager can submit this project for admin delivery review.', 403);
        }

        if ($project->status !== 'completed') {
            return ApiResponse::error('Mark the project as completed before submitting it for admin review.', 422);
        }

        if ($project->status === 'closed') {
            return ApiResponse::error('This project is closed and read-only. Reopen it first to submit delivery.', 422);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:' . self::DELIVERY_MIMES, 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::DELIVERY_MAX_KB)],
        ]);

        $file = $validated['file'];

        // Store the NEW file before removing the old one — a storage-full
        // (or Drive) failure must leave the previous delivery intact rather
        // than deleting it and then having nothing to replace it with.
        $stored = AttachmentStorageService::store($file, $project->company_id, 'project_delivery', "project-deliveries/{$project->id}");
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        if ($project->delivery_file_path || $project->delivery_google_drive_file_id) {
            AttachmentStorageService::delete(
                $project->delivery_storage_type, $project->delivery_file_path,
                $project->delivery_google_drive_file_id, $project->company_id
            );
        }

        $project->update([
            'delivery_status'                 => 'pending_admin_review',
            'delivery_file_path'              => $stored->filePath,
            'delivery_file_name'              => $stored->originalName,
            'delivery_file_type'              => $stored->mimeType,
            'delivery_file_size'              => $stored->sizeBytes,
            'delivery_storage_type'           => $stored->storageType,
            'delivery_google_drive_file_id'   => $stored->googleDriveFileId,
            'delivery_google_drive_folder_id' => $stored->googleDriveFolderId,
            'delivery_submitted_at'           => now(),
            'delivery_submitted_by'           => $user->id,
            'delivery_approved_at'            => null,
            'delivery_approved_by_admin_id'   => null,
        ]);

        $project->logActivity('project_delivery_submitted', "{$user->name} submitted the final project package for admin review.", $user->name, [
            'file_name' => $project->delivery_file_name,
        ]);
        $this->logActivity($project->company_id, 'project_delivery_submitted', 'Project', $project->id, [
            'file_name' => $project->delivery_file_name,
        ]);

        NotificationService::notifyCompanyAdmins($project->company_id, null, [
            'actor_user_id' => $user->id,
            'module'        => 'project_management',
            'type'          => 'project_delivery_submitted',
            'title'         => 'Project ready for delivery review',
            'message'       => "{$user->name} submitted \"{$project->name}\" for admin approval.",
            'entity_type'   => 'Project',
            'entity_id'     => $project->id,
            'url'           => "/admin/projects/{$project->id}",
        ]);

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project submitted for admin review');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canEditProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);

        // Closed is a terminal, read-only state — only reopen() can move a
        // project out of it.
        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $companyId = $this->activeCompanyId();
        $user = $this->user();

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            // 'completed' and 'closed' are reached only via complete()/close()
            // below (readiness checks, activity log, notifications) — never as
            // a bare status write through this generic update endpoint.
            'status'      => ['sometimes', 'in:planning,active,on_hold,blocked,cancelled'],
            'priority'    => ['sometimes', 'in:low,medium,high,urgent'],
            // Start Date is fixed at project-creation time — never editable
            // here.
            'deadline'    => ['nullable', 'date'],
            'budget'      => ['nullable', 'numeric', 'min:0'],
            // Same visibility rule as Api\User\ClientController::visibleClients()
            // — without canViewAllCompanyClients, only a client this actor
            // already manages (account manager / their lead / their invoice /
            // their own project) can be assigned here.
            'client_id'   => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('clients', 'id')->where('company_id', $companyId),
                function ($attribute, $value, $fail) use ($user) {
                    if ($value === null || $this->can('canViewAllCompanyClients')) {
                        return;
                    }
                    $visible = \App\Models\Client::where('id', $value)
                        ->where(function ($q) use ($user) {
                            $q->where('account_manager', $user->id)
                                ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                                ->orWhereHas('invoices', fn ($i) => $i->where('created_by', $user->id))
                                ->orWhereHas('projects', fn ($p) => $p->where('seller_id', $user->id));
                        })->exists();
                    if (!$visible) {
                        $fail('You can only assign a client you manage.');
                    }
                },
            ],
        ]);

        $before = $project->only(array_keys($validated));
        $project->update($validated);

        $changedFields = collect(array_keys($validated))
            ->filter(fn ($field) => ($before[$field] ?? null) != $project->{$field})
            ->values();

        if ($changedFields->contains('status')) {
            $project->logActivity('status_changed', "{$this->user()->name} changed status from {$before['status']} to {$project->status}.", $this->user()->name, [
                'from' => $before['status'] ?? null,
                'to' => $project->status,
            ]);
        }

        $otherFields = $changedFields->reject(fn ($field) => $field === 'status')->values();
        if ($otherFields->isNotEmpty()) {
            $project->logActivity('updated', "{$this->user()->name} updated " . $otherFields->implode(', ') . '.', $this->user()->name, [
                'fields' => $otherFields->all(),
            ]);
        }

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project updated');
    }

    // Picker list for assignee/team-member selection — active, same-company
    // users only. Includes email/role/Project-Management-access-status so
    // the PM can see upfront whether a candidate can actually use the module
    // after being added (team membership and permission grants are separate,
    // unlinked actions — this surfaces that instead of hiding it).
    private function sellerAssignmentService(): ProjectSellerAssignmentService
    {
        return app(ProjectSellerAssignmentService::class);
    }

    // GET /projects/sellers — active Sellers of this staff member's own
    // company, for the "Assign/Switch Seller" dropdown. Gated the same as
    // assignSeller() below — no point exposing the picker to someone who
    // can't act on it.
    public function sellers(): JsonResponse
    {
        if (!$this->can('canAssignProjectSeller')) {
            return ApiResponse::error('You do not have permission to assign seller to project.', 403);
        }

        // Scoped to activeCompanyId() (the company switcher), not the user's
        // static company_id — a Lead Manager assigned to multiple companies
        // must only ever be offered that company's own Sellers here.
        //
        // holdingRole(), not role_type: switching the project to a Team Lead /
        // "PM Sales" (Seller held as a SECOND role) makes them the project's
        // primary seller exactly as a plain Seller would, and
        // ProjectSellerAssignmentService::assignableSeller() has always
        // accepted them — but this picker never listed them, so the one
        // eligible person a project usually needs to be handed to could not be
        // chosen here. The Admin-side picker already used holdingRole().
        $companyId = $this->activeCompanyId();
        $sellers = User::ofCompany($companyId)
            ->where('is_active', true)
            ->where('status', 'active')
            ->holdingRole($companyId, 'seller')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return ApiResponse::success($sellers);
    }

    // PATCH /projects/{id}/seller — assign or switch this project's Seller.
    // Company Admin has an equivalent, unrestricted endpoint on the Admin
    // guard; here a PM (or anyone else) needs canAssignProjectSeller, which
    // is never granted by default (see RoleDefaultPermissions) — Company
    // Admin must explicitly grant it. A plain Seller can never hold this key
    // (see RoleDefaultPermissions::MAP's 'seller' bundle), so this also
    // structurally satisfies "Seller cannot switch project seller."
    public function assignSeller(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canAssignProjectSeller')) {
            return ApiResponse::error('You do not have permission to assign seller to project.', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $user = $this->user();

        // NO SECONDARY SELLER MAY REPLACE THE PRIMARY SELLER (2026-10-09).
        //
        // canAssignProjectSeller alone was the whole gate here, and it is a
        // DEFAULT grant for role_type='project_manager' (see
        // RoleDefaultPermissions) — so a "PM Sales" sitting anywhere in this
        // project's Sales hierarchy, at any depth, held it and could re-point
        // projects.seller_id at will. Being able to SEE a project (and
        // visibleProjects() is deliberately broad: creator, past seller, task
        // assignee, team member) has never been authority to re-own it.
        //
        // Once a project HAS a primary seller, only its literal Project
        // Manager and that seller themselves may change it. A project with
        // none yet is left open so it can be assigned at all. Company Admin
        // is unaffected — Api\Admin\ProjectController is a separate guard.
        if ($project->seller_id
            && (int) $project->project_manager_id !== (int) $user->id
            && (int) $project->seller_id !== (int) $user->id) {
            return ApiResponse::error('Only this project\'s Project Manager or its current Seller can change the project\'s Seller.', 403);
        }

        $validated = $request->validate([
            'seller_id' => ['required', 'integer'],
            'reason'    => ['nullable', 'string', 'max:1000'],
        ]);

        $seller = $this->sellerAssignmentService()->assignableSeller($user->company_id, (int) $validated['seller_id']);
        if (!$seller) {
            return ApiResponse::error('Selected seller does not belong to this company.', 422);
        }

        $result = $this->sellerAssignmentService()->assign(
            $project, $seller, $validated['reason'] ?? null, $user->id, null, $user->name
        );

        return ApiResponse::success(
            $project->fresh(['seller:id,name,email']),
            $result['is_switch'] ? 'Seller switched' : 'Seller assigned'
        );
    }

    // PATCH /projects/{id}/project-manager — assign, switch, or clear this
    // project's Project Manager. Own-Seller-only (this project's linked
    // seller_id must match the caller) — the same ownership gate as
    // Api\User\ProjectMessengerController::eligiblePms()/invitePm(), but
    // extended to support SWAPPING to a different PM: those endpoints only
    // ever supported a first-time assign, erroring on any later change.
    // Backs the "Assigned To" dropdown on the Seller's own /projects list,
    // which needs to let them change their pick, not just set it once.
    public function assignProjectManager(Request $request, int $id): JsonResponse
    {
        $project = $this->visibleProjects()->findOrFail($id);
        $user = $this->user();

        if ($user->role_type !== 'seller' || $project->seller_id !== $user->id) {
            return ApiResponse::error('Only this project\'s Seller can assign a Project Manager.', 403);
        }

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'project_manager_id' => ['nullable', 'integer'],
        ]);
        $pmId = $validated['project_manager_id'] ?? null;

        $pm = null;
        if ($pmId) {
            // ofCompany() (not a raw company_id match) so a Project Manager
            // assigned to more than one company still resolves here when
            // this project's company is their secondary one, not just
            // whichever company their users.company_id column happens to
            // point at.
            $pm = User::ofCompany($project->company_id)
                ->where('id', $pmId)
                ->where('role_type', 'project_manager')
                ->where('is_active', true)
                ->first();
            if (!$pm) {
                return ApiResponse::error('That user is not an active Project Manager at your company.', 422);
            }
        }

        // The project's own Seller can carry a cosmetic 'project_manager'
        // team row purely so the "Project Manager" column shows a name
        // instead of "Unassigned" on a self-run project (see
        // ProjectSellerAssignmentService::assign()) — never treated as a
        // real PM here, same exclusion invitePm() uses.
        $existingPm = $project->teamMembers()
            ->where('role_in_project', 'project_manager')
            ->where('user_id', '!=', $project->seller_id)
            ->first();

        if ($existingPm && $existingPm->user_id === $pmId) {
            return ApiResponse::success($project->fresh(), 'Project Manager unchanged');
        }

        if ($existingPm) {
            $removedUserId = $existingPm->user_id;
            $removedUserName = User::find($removedUserId)?->name ?? 'Unknown';
            $existingPm->delete();
            ProjectChatService::removeParticipantIfNoLongerEligible($project, $removedUserId);
            $project->logActivity('team_member_removed', "{$user->name} removed {$removedUserName} from the project team.", $user->name, [
                'user_id' => $removedUserId,
            ]);
        }

        // The Seller's own cosmetic 'project_manager' row (see the comment
        // on $existingPm above) is only ever a stand-in for "nobody's been
        // assigned yet". Once a REAL Project Manager takes over, its
        // role_in_project must be corrected — otherwise the Seller keeps
        // showing up as "Project Manager" on any Team/Resources view that
        // reads role_in_project directly. It must NOT be deleted outright
        // though: every Team/Resources view (frontend/app/projects/team/
        // page.tsx, frontend/app/admin/projects/team/page.tsx,
        // frontend/app/admin/projects/[id]/team/page.tsx) already prefers
        // the member's actual role_type ("Seller") over this column for
        // display, via each page's own memberRoleLabel() — so downgrading
        // it to 'team_member' still shows correctly as "Seller" everywhere,
        // while keeping this Seller listed as a team resource on the
        // project they originated, instead of silently dropping off every
        // Team/Resources view the moment a real PM is assigned.
        if ($pm) {
            $sellerCosmeticRow = $project->teamMembers()
                ->where('role_in_project', 'project_manager')
                ->where('user_id', $user->id)
                ->first();
            $sellerCosmeticRow?->update(['role_in_project' => 'team_member']);
        }

        if ($pm) {
            $wasAlreadyOnTeam = $project->teamMembers()->where('user_id', $pm->id)->exists();
            ProjectTeamMember::updateOrCreate(
                ['project_id' => $project->id, 'user_id' => $pm->id],
                ['role_in_project' => 'project_manager', 'assigned_by' => $user->id]
            );
            ProjectChatService::addTeamMember($project, $pm->id);

            if (!$wasAlreadyOnTeam) {
                Notification::create([
                    'user_id'    => $pm->id,
                    'company_id' => $project->company_id,
                    'type'       => 'project_team_assigned',
                    'title'      => 'Added to project team',
                    'body'       => "{$user->name} added you to project \"{$project->name}\" as Project Manager.",
                    'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
                ]);
            }

            $project->logActivity('team_assigned', "{$user->name} assigned {$pm->name} as Project Manager.", $user->name, [
                'to' => $pm->id, 'role_in_project' => 'project_manager',
            ]);
        }

        // Keep the projects.project_manager_id column in sync with the team
        // row — Admin's own Edit Project page (and anything else reading
        // this column directly) would otherwise show "Unassigned" even
        // after a Seller assigns a PM here.
        $project->update(['project_manager_id' => $pm?->id]);

        // Unlike the 'project_team_assigned' notification above (first-time
        // team add only), this fires on every real appointment — including a
        // swap to a PM who was already on the team in some other role. The
        // "same PM again" case returned early further up.
        AssignmentMailService::projectManagerAssigned($project, $pm?->id, $user->name, $user->id);

        return ApiResponse::success($project->fresh(), $pm ? 'Project Manager assigned' : 'Project Manager unassigned');
    }

    public function companyUsers(Request $request): JsonResponse
    {
        // canAssignComplianceUser (compliance module) also powers the
        // Compliance Officer dropdown on the Project Compliance page, which
        // reuses this list — anyCan() only checks the project_management
        // module, so it's checked separately here.
        if (!$this->anyCan(['canCreateTasks', 'canEditTasks', 'canAssignTeamResources', 'canViewTeamResources'])
            && !$this->can('canAssignComplianceUser', 'compliance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();

        // Team/Resources passes ?project_id= so this scopes to THAT
        // project's own company — a multi-company PM/staff member's
        // "currently active company" (the plain $user->company_id fallback
        // below) can easily be a different company than the project they
        // actually have selected, which silently offered the wrong
        // company's users as team-assignment candidates. Falls back to the
        // active-company behavior for callers that don't pass one (e.g. the
        // Support Ticket assignee picker, which has no single project).
        //
        // Deliberately NOT visibleProjects() here — its base query is
        // itself pre-filtered to the currently ACTIVE company, so a project
        // the actor has genuinely selected but whose company differs from
        // whatever's active right now would silently resolve to nothing,
        // falling through to the (wrong) active-company company id below —
        // exactly backwards from the intent. Resolve directly instead,
        // validating the actor's own tie to the project without that
        // active-company pre-filter.
        $companyId = $user->company_id;
        if ($request->filled('project_id')) {
            $candidate = Project::find((int) $request->query('project_id'));
            if ($candidate) {
                $hasDirectTie = (int) $candidate->project_manager_id === $user->id
                    || (int) $candidate->created_by === $user->id
                    || (int) $candidate->seller_id === $user->id
                    || $candidate->teamMembers()->where('user_id', $user->id)->exists();
                $hasCompanyAccess = $this->can('canViewAllCompanyProjects') && CompanyUserAssignment::where('user_id', $user->id)
                    ->where('company_id', $candidate->company_id)
                    ->where('status', 'active')
                    ->exists();
                if ($hasDirectTie || $hasCompanyAccess) {
                    $companyId = $candidate->company_id;
                }
            }
        }

        // ofCompany(), not a raw company_id match (changed 2026-09-03).
        //
        // The rule is still "a project's team can only be staffed from that
        // project's own company" — but raw company_id is the wrong test for
        // membership of it. Adding an existing user to a second company
        // (Api\Admin\UserController::store()/invite()'s existing-user branch)
        // only ever writes a CompanyUserAssignment row; it never moves
        // users.company_id, which stays pinned to whichever company first
        // created the account. So every genuinely multi-company staff member
        // was invisible in their SECOND company's pickers — a company whose
        // only Project Manager joined that way had a permanently empty PM
        // dropdown, while assignProjectManager() below (already ofCompany())
        // would have accepted that exact person. Listing and enforcement now
        // agree.
        $users = User::ofCompany($companyId)
            ->where('is_active', true)
            // companyRoles for THIS company only: the picker filters on what
            // someone is HERE, and another company's roles must not leak into
            // that decision.
            ->with(['companyRoles' => fn ($r) => $r->where('company_id', $companyId)])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role_type', 'custom_role_label']);

        $withAccess = UserCompanyPermission::where('company_id', $companyId)
            ->where('module_key', 'project_management')
            ->whereIn('user_id', $users->pluck('id'))
            ->distinct()
            ->pluck('user_id')
            ->all();

        $users = $users->map(function ($u) use ($withAccess, $companyId) {
            $u->has_project_management_access = in_array($u->id, $withAccess);
            // Every role held in this company, primary first. The team picker
            // reads this instead of role_type alone, so a user given Seller as
            // a second role is offered wherever a Seller is — role_type only
            // ever carries their primary. Falls back to role_type for an
            // account with no role rows here (see User::scopeHoldingRole()).
            $roles = $u->rolesIn($companyId);
            $u->roles = $roles !== [] ? $roles : array_filter([$u->role_type]);
            unset($u->companyRoles);
            return $u;
        });

        return ApiResponse::success($users);
    }

    public function assignTeam(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canAssignTeamResources')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        // Team members must be real, active members of this project's own
        // company — not just any user id in the system.
        $validated = $request->validate([
            'members'                    => ['required', 'array'],
            'members.*.user_id'          => ['required', 'integer'],
            'members.*.role_in_project'  => ['required', 'in:project_manager,production_user,team_member,reviewer'],
        ]);

        $actor = $this->user();
        $submittedIds = collect($validated['members'])->pluck('user_id')->unique();

        // ofCompany(), matching companyUsers() above — see the long note
        // there for why raw company_id is the wrong membership test. Kept in
        // lock-step deliberately: this endpoint validates exactly the list
        // that endpoint offers, so a name the picker shows can always be
        // saved. Every id below is already known to belong to this company,
        // so the role checks that follow don't need to re-filter by company.
        $validCompanyCount = User::ofCompany($project->company_id)->whereIn('id', $submittedIds)->count();
        if ($validCompanyCount !== $submittedIds->count()) {
            return ApiResponse::error('One or more selected users do not belong to this company.', 422);
        }

        // THE PRIMARY SELLER IS NOT A TEAM RESOURCE.
        //
        // projects.seller_id is the project's own seller — its main seller, not
        // a member of Sales Team Resources — so they must never also hold a
        // project_team_members row, which would list them twice: once as the
        // project's seller and again as one of their own secondary sellers.
        // Becoming the primary seller is the Switch Seller action's job
        // (ProjectSellerAssignmentService::assign), never this endpoint's.
        if ($project->seller_id && $submittedIds->contains((int) $project->seller_id)) {
            return ApiResponse::error('This user is already the project\'s seller, so they can\'t also be added as a team resource.', 422);
        }

        // A SELLER IS NEVER A PRODUCTION RESOURCE.
        //
        // Holding the Seller role makes someone Sales-associated, whatever
        // else they hold: a "PM Design" who is also a Seller belongs in Sales,
        // not in the Design team's roster. The `team` column below already
        // derives that ('sales' whenever they hold Seller), so this refuses the
        // one request that would otherwise contradict it — being added under a
        // production role_in_project — rather than silently filing them
        // somewhere the caller did not ask for.
        //
        // Being able to MANAGE Production Team Resources (which the primary
        // seller and the project's PM both can) is a different thing from being
        // selectable AS one; only the latter is refused here.
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

        // holdingRole(), not role_type: a "PM Sales"-style actor (Seller held
        // as a SECOND role) must still be recognized as a Seller by both
        // gates below — both to let them through the "Admin/PM only" block
        // (they're covered by the narrower Seller rule instead) and to hold
        // them TO that narrower rule rather than the generic "every other
        // actor" whitelist a few lines down, which would otherwise let a
        // primary-Project-Manager-who-also-holds-Seller add a
        // Production/Developer/Designer/QA member directly — PM/Admin
        // territory, not theirs.
        $actorIsSeller = User::where('id', $actor->id)->holdingRole($project->company_id, 'seller')->exists();

        // STAFFING IS THE PROJECT'S OWN SELLER'S JOB, NOT EVERY SELLER'S.
        //
        // Once a project has a primary seller (projects.seller_id), they are
        // the one seller who staffs it. A plain secondary seller they brought
        // in — or any other single-role seller in the company who can reach
        // this project — may see their sub-team but may not add anyone: the
        // roster must not grow from a side of the conversation that doesn't
        // own it.
        //
        // THE SECONDARY SELLER EXCEPTION IS LINEAGE-BASED (2026-10-08, on
        // request — it was role-count-based before: Seller plus any second
        // role in this company, the Upseller / "PM Sales" shape). What
        // decides it now is WHO put the actor on this project, read straight
        // off their own project_team_members.assigned_by row:
        //
        //   added by the literal PM, by the primary seller, or by a Company
        //   Admin (assigned_by null — an admin is not a User row, see
        //   Api\Admin\ProjectController::assignTeam) => SECONDARY SELLER, may
        //   bring in further Sellers;
        //
        //   added by one of those secondary sellers => LEAF sales resource,
        //   view-only, may add nobody.
        //
        // So the chain stops at depth two instead of growing indefinitely,
        // and how many roles someone holds no longer enters into it. Sales
        // Team Resources is still the only roster this reaches — Production
        // stays the primary seller's and the PM's, exactly as for every other
        // seller, whatever else the actor holds.
        //
        // Two more exemptions. The literal PM keeps their own staffing rights
        // even when they happen to hold Seller as a second role (their
        // authority here is the PM seat, not the role). And a project with no
        // primary seller yet is left on the previous behaviour, so it can
        // still be staffed at all before one is assigned.
        //
        // Company Admin is unaffected — a different guard entirely.
        $actorIsSecondarySeller = $actorIsSeller
            && $project->seller_id
            && (int) $project->seller_id !== (int) $actor->id
            && (int) $project->project_manager_id !== (int) $actor->id;

        if ($actorIsSecondarySeller) {
            // Mirrors getTeamGroups()'s addedByAuthority (frontend/lib/
            // teamResourceGroups.ts) exactly, so the Add form and this gate
            // can never disagree about who may submit.
            //
            // A seller with no team row at all falls here too: merely being
            // able to REACH this project (visibleProjects() is deliberately
            // broad — past seller, creator, task assignee) has never been
            // authority to staff it.
            $actorRow = $project->teamMembers()->where('user_id', $actor->id)->first();
            $authorityIds = array_values(array_filter([
                (int) $project->project_manager_id,
                (int) $project->seller_id,
            ]));
            $addedByAuthority = $actorRow
                && ($actorRow->assigned_by === null
                    || in_array((int) $actorRow->assigned_by, $authorityIds, true));
            if (!$addedByAuthority) {
                return ApiResponse::error('Only this project\'s own seller, or a seller its Project Manager added, can add resources to it.', 403);
            }

            // Sales only. Every submitted user must themselves hold Seller —
            // holdingRole() on the TARGET, so bringing in another "PM Sales"
            // counts as Sales here exactly as the `team` column below files
            // them. Anything else is a Production add, which is not theirs.
            $nonSellerIds = User::whereIn('id', $submittedIds)
                ->whereNot(fn ($q) => $q->holdingRole($project->company_id, 'seller'))
                ->pluck('id');
            if ($nonSellerIds->isNotEmpty()) {
                return ApiResponse::error('As a secondary seller, you can only add another Seller to this project\'s Sales Team.', 403);
            }
        }

        // Adding a Seller to the team is Admin/PM/Seller territory — a
        // Seller's own narrower rule (right below) already governs exactly
        // what THEY may add (a Project Manager or another Seller), so they're
        // exempted from this blanket block. Everyone else holding
        // canAssignTeamResources can still add a plain team member, just not
        // a Seller specifically (mirrors the frontend gate in
        // frontend/app/projects/team/page.tsx's addableUsers filter).
        if ((int) $project->project_manager_id !== (int) $actor->id && !$actorIsSeller) {
            $sellerIds = User::where('role_type', 'seller')
                ->whereIn('id', $submittedIds)
                ->pluck('id');
            if ($sellerIds->isNotEmpty()) {
                return ApiResponse::error('Only the Company Admin or this project\'s Project Manager can add a Seller to the team.', 403);
            }
        }

        // A Seller may only ever hand this project to a Project Manager or
        // bring in another Seller — never a Production/Developer/Designer/
        // QA/Team Member directly, that's PM/Admin territory. Mirrors the
        // frontend gate in frontend/app/projects/team/page.tsx (Seller actor
        // narrowed to eligibleRoles = ['project_manager'], plus 'seller' via
        // the isLiteralPm branch). holdingRole() on the TARGET too, so a
        // Seller (or PM Sales) can bring in another Seller-as-second-role
        // person, not just a primary-role one.
        if ($actorIsSeller) {
            $disallowedIds = User::whereIn('id', $submittedIds)
                ->whereNot(fn ($q) => $q->holdingRole($project->company_id, ['project_manager', 'seller']))
                ->pluck('id');
            if ($disallowedIds->isNotEmpty()) {
                return ApiResponse::error('As a Seller, you can only add a Project Manager or another Seller to the project team.', 403);
            }
        }

        // A Lead Manager may only ever hand this project to a Project
        // Manager — never a Seller/Production/Developer/Designer/QA/Team
        // Member, not even a Seller (unlike the Seller-actor rule above).
        // Staffing the rest of the team beyond that is PM/Admin territory.
        // Mirrors the frontend gate in frontend/app/projects/team/page.tsx
        // (Lead Manager actor narrowed to eligibleRoles = ['project_manager'],
        // with no isLiteralPm/'seller' exception).
        if ($actor->role_type === 'lead_manager') {
            $disallowedIds = User::whereIn('id', $submittedIds)
                ->where('role_type', '!=', 'project_manager')
                ->pluck('id');
            if ($disallowedIds->isNotEmpty()) {
                return ApiResponse::error('As a Lead Manager, you can only add a Project Manager to the project team.', 403);
            }
        }

        // Every OTHER actor (not Seller, not Lead Manager — e.g. a Project
        // Manager, or any other role holding canAssignTeamResources) may
        // still only add users whose job actually belongs on a project team
        // — never an HR/Finance/Compliance/Invoice/Client-role_type user
        // added by mistake or a direct API call. A Seller entry here has
        // already cleared the "who can add a Seller" gate above. Previously
        // this whitelist (frontend/app/projects/team/page.tsx's
        // TEAM_ELIGIBLE_ROLES) was enforced client-side only — the API
        // itself accepted any company user regardless of role_type.
        if (!in_array($actor->role_type, ['seller', 'lead_manager'], true)) {
            $eligibleRoles = ['project_manager', 'production', 'developer', 'designer', 'qa', 'team_member', 'seller'];
            // holdingRole(), not whereNotIn('role_type'): role_type carries
            // only a person's PRIMARY role, so someone whose primary is HR but
            // who also holds QA in this company was rejected here even though
            // the picker (which reads every held role) had just offered them.
            // Widening only — a user with no eligible role at all is still
            // refused, exactly as before.
            $disallowedIds = User::whereIn('id', $submittedIds)
                ->whereNot(fn ($q) => $q->holdingRole($project->company_id, $eligibleRoles))
                ->pluck('id');
            if ($disallowedIds->isNotEmpty()) {
                return ApiResponse::error('This user\'s role isn\'t eligible for a project team.', 422);
            }
        }

        // A project may only ever have ONE user filling the literal
        // role_in_project='project_manager' seat — the chat hub
        // (isPM()/isProjectPmUser()) and projects.project_manager_id both
        // assume exactly one. Mirrors the frontend picker in
        // frontend/app/projects/team/page.tsx, which already drops that role
        // out of the "Add Team Member" list once one is present.
        //
        // Counted on role_in_project (2026-09-28, on request — previously
        // counted on the candidate's intrinsic role_type, which refused ANY
        // second person whose company-wide role happens to be Project
        // Manager, even added as a plain production_user). "PM Design"/"PM
        // Developer"/"PM SEO" all hold role_type='project_manager' company-
        // wide but are added here as production_user, so they no longer
        // compete for this one seat.
        //
        // Checked against the union of already-existing members' role
        // (unless THIS payload overrides it) and this request's payload, so
        // re-submitting the SAME existing PM (e.g. a role change save) never
        // trips it, but adding a second, different one does.
        //
        // SELLERS ARE NO LONGER CAPPED (2026-09-15, on request) — see the
        // matching block in Api\Admin\ProjectController::assignTeam(). Adding
        // one never disturbs the others: only the ids in THIS payload are
        // upserted, and (project_id, user_id) is unique.
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
                    'assigned_by' => $actor->id,
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
                $project->logActivity('team_assigned', "{$actor->name} added {$memberName} to the project team as " . str_replace('_', ' ', $member['role_in_project']) . '.', $actor->name, [
                    'to' => $member['user_id'],
                    'role_in_project' => $member['role_in_project'],
                ]);
            } elseif ($wasRole !== $member['role_in_project']) {
                $project->logActivity('team_member_role_changed', "{$actor->name} changed {$memberName}'s project role from " . str_replace('_', ' ', $wasRole) . ' to ' . str_replace('_', ' ', $member['role_in_project']) . '.', $actor->name, [
                    'user_id' => $member['user_id'],
                    'from' => $wasRole,
                    'to' => $member['role_in_project'],
                ]);
            }

            // Skip self-notification — a PM re-saving/expanding the team
            // roster can legitimately include themselves in the payload.
            if ($member['user_id'] === $actor->id) {
                continue;
            }

            Notification::create([
                'user_id'    => $member['user_id'],
                'company_id' => $project->company_id,
                'type'       => 'project_team_assigned',
                'title'      => 'Added to project team',
                'body'       => "{$actor->name} added you to project \"{$project->name}\".",
                'data'       => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);
        }

        $this->logActivity($project->company_id, 'team_assigned', 'Project', $project->id, $validated);

        return ApiResponse::success($project->teamMembers()->with('user:id,name,role_type')->get(), 'Team updated');
    }

    public function removeTeamMember(int $id, int $memberId): JsonResponse
    {
        if (!$this->can('canAssignTeamResources')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $member = $project->teamMembers()->where('id', $memberId)->first();

        // Each sub-team stays separate: the literal PM (or Company Admin, a
        // separate controller entirely) runs the whole roster, but anyone
        // else holding canAssignTeamResources — a Seller/PM Sales in Sales, a
        // discipline-lead PM (PM Design/PM Developer/PM SEO) in Production —
        // may only remove a member THEY personally added. Mirrors the
        // Upseller's own ownership rule for chat participants
        // (ProjectMessengerController::upsellerOwnsParticipant()), generalized
        // here to the whole Team Resources roster rather than just chat.
        $actor = $this->user();
        $isLiteralPm = (int) $project->project_manager_id === (int) $actor->id;
        if ($member && !$isLiteralPm && (int) $member->assigned_by !== (int) $actor->id) {
            return ApiResponse::error('You can only remove a team member you added yourself.', 403);
        }

        $removedUserId = $member?->user_id;
        $removedUserName = $removedUserId ? (User::find($removedUserId)?->name ?? 'Unknown') : 'Unknown';
        $project->teamMembers()->where('id', $memberId)->delete();

        // Losing their team row can also mean losing their project chat
        // access — but only if they're not still tied to the project some
        // other way (e.g. still assigned to an open task).
        if ($removedUserId) {
            ProjectChatService::removeParticipantIfNoLongerEligible($project, $removedUserId);
            $project->logActivity('team_member_removed', "{$this->user()->name} removed {$removedUserName} from the project team.", $this->user()->name, [
                'user_id' => $removedUserId,
            ]);

            // A removed PM/Seller must not leave a dangling project_manager_id/
            // seller_id reference — otherwise assignTeam()'s one-PM/one-Seller
            // cap (which only counts CURRENT team rows) stops seeing them,
            // silently letting a second PM/Seller be added while the stale FK
            // still points at the one who's no longer on the team.
            $fkUpdates = [];
            if ((int) $project->project_manager_id === (int) $removedUserId) $fkUpdates['project_manager_id'] = null;
            if ((int) $project->seller_id === (int) $removedUserId) $fkUpdates['seller_id'] = null;
            if ($fkUpdates) $project->update($fkUpdates);
        }

        return ApiResponse::success(null, 'Team member removed');
    }

    private function completionService(): ProjectCompletionService
    {
        return app(ProjectCompletionService::class);
    }

    // GET /projects/{id}/completion-status — backs the "Mark as Complete"
    // pre-flight checklist modal. Gated on the same permission as the action
    // itself (canCompleteProjects) rather than canViewProjects, since only
    // someone who could actually complete the project needs the checklist.
    public function completionStatus(int $id): JsonResponse
    {
        if (!$this->can('canCompleteProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $service = $this->completionService();
        $readiness = $service->checkReadiness($project);

        return ApiResponse::success([
            'status'             => $project->status,
            'ready'              => $readiness['ready'],
            'blockers'           => $readiness['blockers'],
            'has_unpaid_invoice' => $service->hasUnpaidInvoice($project),
        ]);
    }

    // Production User, Team Member, Seller, and Viewer never hold
    // canCompleteProjects (not part of any role's defaults — see
    // RoleDefaultPermissions) so they're excluded from this action simply by
    // never having the permission, without needing a role_type check here.
    // Activate a draft project — the name-only stub auto-created when a client's
    // invoice payment starts one (see App\Services\PaymentProjectStartService).
    // canActivateProjects is what both reveals drafts to this user (see
    // visibleProjects()) and permits this transition; it is granted to no role
    // by default, so Company Admin must hand it out deliberately.
    //
    // Folders are created here rather than at auto-creation time: a draft that
    // nobody activates shouldn't leave storage behind.
    public function activate(int $id): JsonResponse
    {
        if (!$this->can('canActivateProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $user = $this->user();

        if ($project->status !== 'draft') {
            return ApiResponse::error("Only a draft project can be activated — this one is {$project->status}.", 422);
        }

        $project->update(['status' => 'active']);

        if ($project->folders()->count() === 0) {
            $this->createProjectFolders($project, $user->id);
        }

        $project->logActivity('activated', "Draft project activated by {$user->name}.", $user->name);
        $this->logActivity($project->company_id, 'activated', 'Project', $project->id);

        $this->notifyLifecycle($project, 'project_activated', 'Project activated', "\"{$project->name}\" was activated by {$user->name}.");

        $companyName = \App\Models\Company::find($project->company_id)?->invoicingProfile()['name'] ?? config('app.name');
        $this->completionService()->notifyClientOfActivation($project, $companyName);

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project activated');
    }

    public function complete(int $id): JsonResponse
    {
        if (!$this->can('canCompleteProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $user = $this->user();

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
            'status'       => 'completed',
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);

        $project->logActivity('completed', "Project marked as completed by {$user->name}.", $user->name);
        $this->logActivity($project->company_id, 'completed', 'Project', $project->id);

        $this->notifyLifecycle($project, 'project_completed', 'Project completed', "\"{$project->name}\" was marked as completed by {$user->name}.");

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project marked as completed');
    }

    public function close(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canCloseProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $user = $this->user();

        $validated = $request->validate([
            'force'                  => ['nullable', 'boolean'],
            'reason'                 => ['nullable', 'string', 'max:1000'],
            'confirm_unpaid_invoice' => ['nullable', 'boolean'],
        ]);
        $force = (bool) ($validated['force'] ?? false);

        if ($force && !$this->can('canForceCloseProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        // A never-activated draft (or still-unpaid placeholder) isn't
        // something to "close" — it's a stub.
        if ($project->isDraft()) {
            return ApiResponse::error("Activate this project before closing it — it is currently {$project->status}.", 422);
        }

        if ($project->status === 'closed') {
            return ApiResponse::error('Project is already closed.', 422);
        }

        if (!in_array($project->status, ['completed', 'approved_locked']) && !$force) {
            $project->logActivity('close_blocked', "Close attempted by {$user->name} but project is not yet Completed.", $user->name);
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
            'closed_by'             => $user->id,
            'close_reason'          => $validated['reason'] ?? null,
            'reopen_requested_at'   => null,
            'reopen_requested_by'   => null,
            'reopen_request_reason' => null,
        ]);

        $project->logActivity(
            'closed',
            "Project closed by {$user->name}." . (!empty($validated['reason']) ? " Reason: {$validated['reason']}" : ''),
            $user->name
        );
        $this->logActivity($project->company_id, 'closed', 'Project', $project->id);

        $this->notifyLifecycle($project, 'project_closed', 'Project closed', "\"{$project->name}\" was closed by {$user->name}.");

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project closed');
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canReopenProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $user = $this->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if (!in_array($project->status, ['completed', 'closed'])) {
            return ApiResponse::error('Only a Completed or Closed project can be reopened.', 422);
        }

        $project->update([
            'status'                => 'active',
            'reopened_at'           => now(),
            'reopened_by'           => $user->id,
            'reopen_reason'         => $validated['reason'],
            'completed_at'          => null,
            'completed_by'          => null,
            'completed_by_admin_id' => null,
            'closed_at'             => null,
            'closed_by'             => null,
            'closed_by_admin_id'    => null,
            'close_reason'          => null,
        ]);

        $project->logActivity('reopened', "Project reopened by {$user->name}. Reason: {$validated['reason']}", $user->name);
        $this->logActivity($project->company_id, 'reopened', 'Project', $project->id);

        $this->notifyLifecycle($project, 'project_reopened', 'Project reopened', "\"{$project->name}\" was reopened by {$user->name}. Reason: {$validated['reason']}");

        return ApiResponse::success($this->presentProject($project->fresh()), 'Project reopened');
    }

    // A PM can only ask — reopen() above still requires status in
    // ['completed','closed'], which structurally excludes 'approved_locked',
    // so this is the only door out of a lock for anyone but Admin. Reuses
    // canReopenProjects rather than a new permission key, since it's the
    // same underlying capability just split into request/approve halves —
    // mirrors submitDelivery() -> Api\Admin\ProjectController::
    // approveDelivery()/deliverToClient()'s submit/approve shape.
    public function requestReopen(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canReopenProjects')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProjects()->findOrFail($id);
        $user = $this->user();

        if ($project->status !== 'approved_locked') {
            return ApiResponse::error('Only a locked project can have its reopen requested.', 422);
        }

        if ($project->reopen_requested_at) {
            return ApiResponse::error('A reopen request is already pending admin approval.', 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $project->update([
            'reopen_requested_at'   => now(),
            'reopen_requested_by'   => $user->id,
            'reopen_request_reason' => $validated['reason'],
        ]);

        $project->logActivity('reopen_requested', "{$user->name} requested this project be reopened. Reason: {$validated['reason']}", $user->name);
        $this->logActivity($project->company_id, 'reopen_requested', 'Project', $project->id);

        NotificationService::notifyCompanyAdmins($project->company_id, null, [
            'actor_user_id' => $user->id,
            'module'        => 'project_management',
            'type'          => 'project_reopen_requested',
            'title'         => 'Reopen requested',
            'message'       => "{$user->name} requested \"{$project->name}\" be reopened. Reason: {$validated['reason']}",
            'entity_type'   => 'Project',
            'entity_id'     => $project->id,
            'url'           => "/admin/projects/{$project->id}",
        ]);

        return ApiResponse::success($this->presentProject($project->fresh()), 'Reopen requested — an Admin will review it');
    }

    // Mirrors Api\Admin\ProjectController::activity() exactly (SystemAuditLog
    // + ProjectComment merged into one chronological feed), scoped through
    // visibleProjects() instead of companyIds() so a PM/staff member gets the
    // same unified timeline Company Admin already has.
    public function activity(int $id): JsonResponse
    {
        $project = $this->visibleProjects()->findOrFail($id);
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
            ->where('action', 'not like', '%_comment_added')
            ->get()
            ->map(fn ($log) => [
                'type'        => 'log',
                'action'      => $log->action,
                'entity_type' => $log->entity_type,
                'created_at'  => $log->created_at,
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

    // Notifies PM/team/production/seller via the existing per-user
    // Notification channel, plus a visibility='client' system comment if
    // Client Portal is active — the only client-facing channel this codebase
    // has. Excludes the acting user themselves from the fan-out.
    private function notifyLifecycle(Project $project, string $type, string $title, string $body): void
    {
        $service = $this->completionService();
        $actingUserId = $this->user()->id;
        $targetUserIds = collect($service->notificationTargetUserIds($project))
            ->reject(fn ($userId) => $userId === $actingUserId);

        foreach ($targetUserIds as $userId) {
            Notification::create([
                'user_id' => $userId, 'company_id' => $project->company_id,
                'type' => $type, 'title' => $title, 'body' => $body,
                'data' => ['project_id' => $project->id, 'link' => "/projects/{$project->id}"],
            ]);
        }

        // Same internal recipients by email — see the matching note in
        // Api\Admin\ProjectController::notifyLifecycle().
        EventMailService::projectLifecycle(
            $project, $type, $body,
            $targetUserIds->map(fn ($id) => ['user_id' => $id])->all(),
        );

        if ($service->clientPortalActive($project)) {
            ProjectComment::create([
                'company_id'     => $project->company_id,
                'project_id'     => $project->id,
                'author_user_id' => $actingUserId,
                'body'           => $body,
                'visibility'     => 'client',
            ]);
        }
    }
}
