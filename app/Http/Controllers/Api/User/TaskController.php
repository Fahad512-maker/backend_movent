<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use ScopesToActiveCompany;

    private function user() { return auth('sanctum')->user(); }
    // trim()->? , not `?? 'User'` — a blank/whitespace-only name would
    // otherwise pass through and leave a History entry with an empty actor.
    private function userName(): string { return trim((string) ($this->user()->name ?? '')) ?: 'User'; }

    // Tasks can only be assigned to a real user of this staff member's own
    // company who is actually on THIS project's team (added via "Manage
    // Team" — project_team_members, not just any company user), OR to any
    // active Seller of the project's company. The Seller carve-out is
    // deliberate (2026-09-04 request): a Seller never gets a
    // project_team_members row (see Api\User\ProjectMessengerController's
    // notes on that), yet a PM must be able to hand work/follow-up to ANY of
    // the company's Sellers, not only one already on the team. A Client
    // portal account can never be an assignee. A Project Manager genuinely
    // on the team CAN be.
    //
    // Applies uniformly regardless of who's doing the assigning — but note
    // that WHO may set assigned_to at all is a separate question, gated in
    // update() (worker roles and Sellers can never change it).
    private function assignedToRule(Project $project)
    {
        $teamMemberIds = $project->teamMembers()->pluck('user_id');
        $companyId = $project->company_id;

        // No separate company_id check on the team branch — whereIn('id',
        // $teamMemberIds) already restricts it to actual members of THIS
        // project's team, which is a strictly narrower, more reliable
        // guarantee (assigning them to the team already validated their
        // company membership, including a multi-company member whose raw
        // company_id column points at a different company than this one).
        // The Seller branch has no team row to lean on, so it validates
        // company membership itself, exactly the way sellerOptions() below
        // builds the picker this rule has to accept.
        //
        // Rule::exists()->where() only supports 2-arg (column, value) equality
        // — a 3-arg (column, operator, value) call silently misparses, so a
        // closure is required for anything else.
        return Rule::exists('users', 'id')->where(function ($query) use ($teamMemberIds, $companyId) {
            $query->where(function ($q) use ($teamMemberIds) {
                $q->whereNotIn('role_type', ['seller', 'client'])
                  ->whereIn('id', $teamMemberIds->isNotEmpty() ? $teamMemberIds->all() : [0]);
            })->orWhere(function ($q) use ($companyId) {
                $q->where('role_type', 'seller')
                  ->where('is_active', true)
                  ->where('company_id', $companyId);
            });
        });
    }

    // Roles that may never CHANGE a task's assignee — they only ever work the
    // task that was handed to them. Enforced in update() below, which is the
    // one place assigned_to can move after creation; store() is unreachable
    // for these roles anyway (canCreateTasks/canCreateLinkedProjectTask are
    // no longer role defaults for any of them, see RoleDefaultPermissions),
    // and a Company Admin who re-grants those permissions by hand still
    // can't reopen reassignment, since this is a role check, not a
    // permission one. PM and QA are deliberately absent.
    private const NON_REASSIGNING_ROLES = ['developer', 'designer', 'production', 'team_member', 'seller'];

    private function isSeller(): bool
    {
        return $this->user()->role_type === 'seller';
    }

    // A Seller's Task world is exactly the tasks assigned to them — nothing
    // else on the project, and nothing they can create or hand on. Used by
    // index()/indexAll() to hard-scope their list, and by activity()/update()
    // to answer "is this one of theirs?".
    private function scopeToOwnAssignedTasks($query)
    {
        return $query->where('assigned_to', $this->user()->id);
    }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $user->company_id, $user->role_type, 'project_management', $permKey, $result);
        return $result;
    }

    private function logActivity(int $companyId, string $action, string $entityType, int $entityId, array $newValues = []): void
    {
        SystemAuditLog::create([
            'company_id' => $companyId, 'user_id' => $this->user()->id,
            'action' => $action, 'module_key' => 'project_management',
            'entity_type' => $entityType, 'entity_id' => $entityId, 'new_values' => $newValues,
        ]);
    }

    // Mirrors Api\User\ProjectController::visibleProjects() exactly — kept as a
    // duplicate helper (not shared) to match this codebase's existing
    // convention of each User-guard controller owning its own can()/scope logic.
    // Purely permission-based, no Data Scope layer.
    private function project(int $projectId): Project
    {
        $user = $this->user();
        $base = Project::where('company_id', $this->activeCompanyId());

        if ($this->can('canViewAllCompanyProjects')) {
            return $base->findOrFail($projectId);
        }

        return $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhere('created_by', $user->id)
                  ->orWhere('seller_id', $user->id)
                  ->orWhereHas('teamMembers', fn($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn($t) => $t->where('assigned_to', $user->id))
                  ->orWhereHas('lead', fn($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                  ->orWhereHas('client', fn($c) => $c->where('account_manager', $user->id));
            })
            ->findOrFail($projectId);
    }

    // A worker-level user (Production User / Team Member / Developer — no
    // full task-management rights, not the project's own manager) only ever
    // sees their OWN tasks, never the full task list of an otherwise-visible
    // project. Neither canAssignTasks nor canEditTasks alone is included
    // here — canAssignTasks only grants the ABILITY to hand a task off to
    // someone else (once reassigned away, the task must actually disappear
    // from the reassigner's own list, not linger just because they hold that
    // permission), and canEditTasks is a default permission on every project
    // role (see RoleDefaultPermissions::MAP) letting each of them edit/
    // progress their OWN task — it was never meant to be a company-wide
    // "see everyone's tasks" signal, unlike canViewAllCompanyProjects. Only
    // canViewAllCompanyProjects, or being that specific project's manager,
    // still sees everything.
    private function isTaskManager(?Project $project = null): bool
    {
        if ($this->user()->role_type === 'project_manager') {
            return true;
        }
        if ($this->can('canViewAllCompanyProjects')) {
            return true;
        }
        return $project && $project->project_manager_id === $this->user()->id;
    }

    private function managesAnyProject(): bool
    {
        $user = $this->user();

        return Project::where('company_id', $user->company_id)
            ->where('project_manager_id', $user->id)
            ->exists();
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        // A Seller sees exactly the tasks assigned to them on this project and
        // nothing else (2026-09-04 request — a PM can now hand a task to any
        // Seller, so the old blanket 403 here would have made every such
        // assignment a dead end: notified, then unopenable). Deliberately
        // permission-free and hard-scoped instead of going through the
        // $canViewAll/$canViewOwnRequests gate below — a Seller holds no
        // canViewTasks by default, and no permission a Company Admin can
        // grant should ever widen a Seller past their own assigned tasks.
        if ($this->isSeller()) {
            $project = $this->project($projectId);
            $q = $this->scopeToOwnAssignedTasks(
                Task::where('project_id', $project->id)
                    ->with(['assignedTo:id,name', 'productionAssignedTo:id,name', 'assignedBy:id,name', 'project:id,name,company_id'])
                    ->withCount('attachments')
            );
            if ($request->filled('status')) $q->where('status', $request->status);

            return ApiResponse::success($q->orderByDesc('created_at')->get());
        }

        $canViewAll = $this->user()->role_type === 'project_manager' || $this->managesAnyProject() || $this->can('canViewTasks');
        $canViewOwnRequests = $this->can('canCreateLinkedProjectTask');
        if (!$canViewAll && !$canViewOwnRequests) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->project($projectId);

        // project:id,name,company_id — the task DETAIL page loads a single task
        // out of this list (userProjectService.tasks.list() → find by id), so
        // without the relation its "Project" field had nothing to read and
        // always rendered "—" (and canDeleteComment()'s task.project.id check
        // never matched). myTasks()/indexAll() already eager-load it.
        $q = Task::where('project_id', $project->id)->with(['assignedTo:id,name', 'productionAssignedTo:id,name', 'assignedBy:id,name', 'project:id,name,company_id'])->withCount('attachments');

        if (!$canViewAll) {
            $q->where('created_by', $this->user()->id);
        } elseif (!$this->isTaskManager($project) || $request->boolean('mine_only')) {
            $userId = $this->user()->id;
            if ($request->boolean('mine_only')) {
                $q->where('assigned_to', $userId);
            } else {
                // Own regular assigned tasks, plus any task specifically
                // handed to this user for Production/Deployment (any role).
                $q->where(function ($w) use ($userId) {
                    $w->where('assigned_to', $userId)->orWhere('production_assigned_to', $userId);
                });
            }
        }
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('task_number', 'like', "%{$s}%"));
        }

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    // All tasks assigned to the current user across their visible projects.
    // Unlike index()/indexAll(), this deliberately has no canViewTasks/
    // canCreateLinkedProjectTask gate — Developer/Designer/QA/Production/Team
    // Member may use it even without canViewTasks (see Sidebar's "My Tasks"
    // fallback), and a Seller reaches their own assigned tasks through the
    // same fallback (2026-09-04: they are assignable now, so the blanket 403
    // that used to sit here would have hidden the very tasks handed to them).
    // "Own tasks only" is this endpoint's whole definition, so no extra
    // Seller scoping is needed here.
    public function myTasks(Request $request): JsonResponse
    {
        $user = $this->user();

        // project.teamMembers.user is here so the frontend's "Assigned To"
        // reassignment picker can scope its options to this task's own
        // project team, instead of every user in the company (same fix
        // already applied to Api\Admin\TaskController::indexAll()).
        $q = Task::whereHas('project', fn($p) => $p->where('company_id', $user->company_id))
            ->with(['project:id,name,company_id', 'project.teamMembers.user:id,name,role_type,custom_role_label', 'assignedBy:id,name', 'assignedTo:id,name', 'productionAssignedTo:id,name'])
            ->withCount('attachments');

        $q->where(function ($w) use ($user) {
            // Own regular assigned tasks, plus any task specifically handed
            // to this user for the Production/Deployment step (any role).
            $w->where('assigned_to', $user->id)
              ->orWhere('production_assigned_to', $user->id);
        });

        if ($request->filled('status')) $q->where('status', $request->status);

        return ApiResponse::success($q->orderBy('due_date')->get());
    }

    // GET /user/tasks/production-users — every active Production/Developer/
    // Designer-role user in this company, for the OPTIONAL "assign a
    // Production/Deployment user" picker when moving a task to Ready for
    // Production. Same no-permission-gate reasoning as qaUsers() above.
    public function productionUsers(): JsonResponse
    {
        $users = User::ofCompany($this->user()->company_id)
            ->whereIn('role_type', ['production', 'developer', 'designer'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return ApiResponse::success($users);
    }

    // GET /user/tasks/assignable-sellers — every active Seller in this
    // company, for the "Assigned To" picker on the task create/edit forms.
    // Sellers are the one assignable role with no project_team_members row to
    // read them off (the project payload's team_members is where every other
    // candidate comes from), and the 2026-09-04 request was explicitly "any
    // seller", not only one already tied to this project — so they need their
    // own list. Same no-permission-gate reasoning as productionUsers() above:
    // it exposes nothing but names, and assignedToRule() is what actually
    // decides whether a submitted id is acceptable.
    public function assignableSellers(): JsonResponse
    {
        $users = User::ofCompany($this->user()->company_id)
            ->where('role_type', 'seller')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role_type']);

        return ApiResponse::success($users);
    }

    // Cross-project task list across every project the PM/Admin can see —
    // mirrors Admin\TaskController::indexAll(), scoped through visibleProjects()
    // instead of company-wide, since staff visibility is permission-scoped.
    public function indexAll(Request $request): JsonResponse
    {
        // A Seller's cross-project list is exactly their own assigned tasks —
        // same hard scope (and same reasoning) as index()'s Seller branch.
        // myTasks() would answer this too, but the frontend's Tasks page
        // routes here whenever the viewer can "see all", so this stays
        // correct on its own rather than depending on that.
        if ($this->isSeller()) {
            $q = $this->scopeToOwnAssignedTasks(
                Task::whereIn('project_id', $this->visibleProjectIds())
                    ->with(['assignedTo:id,name', 'productionAssignedTo:id,name', 'assignedBy:id,name', 'project:id,name,company_id'])
                    ->withCount('attachments')
            );
            if ($request->filled('status')) $q->where('status', $request->status);
            if ($request->filled('search')) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('task_number', 'like', "%{$s}%"));
            }

            return ApiResponse::success($q->orderByDesc('created_at')->get());
        }

        $canViewAll = $this->user()->role_type === 'project_manager' || $this->managesAnyProject() || $this->can('canViewTasks');
        $canViewOwnRequests = $this->can('canCreateLinkedProjectTask');
        if (!$canViewAll && !$canViewOwnRequests) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();
        $visibleProjectIds = $this->visibleProjectIds();

        // project.teamMembers.user is here so the frontend's "Assigned To"
        // reassignment picker can scope its options to this task's own
        // project team, instead of every user in the company (same fix
        // already applied to Api\Admin\TaskController::indexAll()).
        $q = Task::whereIn('project_id', $visibleProjectIds)
            ->with(['assignedTo:id,name', 'productionAssignedTo:id,name', 'assignedBy:id,name', 'project:id,name,company_id', 'project.teamMembers.user:id,name,role_type,custom_role_label'])
            ->withCount('attachments');

        if (!$canViewAll) {
            $q->where('created_by', $user->id);
        } elseif (!$this->isTaskManager()) {
            $q->where(function ($w) use ($user) {
                // A Project Manager should see every task on projects they
                // manage, no matter who the task is assigned to. They may
                // still also see their own handoff/assigned tasks on other
                // visible projects.
                $w->whereHas('project', fn ($p) => $p->where('project_manager_id', $user->id))
                  ->orWhere('assigned_to', $user->id)
                  ->orWhere('production_assigned_to', $user->id);
            });
        } elseif ($request->filled('assigned_to')) {
            $q->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('status'))     $q->where('status', $request->status);
        if ($request->filled('priority'))   $q->where('priority', $request->priority);
        if ($request->filled('project_id')) $q->where('project_id', $request->project_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('task_number', 'like', "%{$s}%"));
        }

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    // GET /user/tasks/{id}/lookup — resolves a bare task id to its
    // project_id for the guard-agnostic /task/{id} share-link redirector.
    // Scoped to this user's own visible projects; the destination User task
    // page does its own full permission check once it has a project_id.
    public function lookup(int $id): JsonResponse
    {
        $task = Task::whereIn('project_id', $this->visibleProjectIds())->findOrFail($id);

        return ApiResponse::success(['project_id' => $task->project_id]);
    }

    // Mirrors Api\User\ProjectController::visibleProjects(), returning ids only.
    private function visibleProjectIds(): array
    {
        $user = $this->user();
        $base = Project::where('company_id', $user->company_id);

        if ($this->can('canViewAllCompanyProjects')) {
            return $base->pluck('id')->all();
        }

        return $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhere('created_by', $user->id)
                  ->orWhere('seller_id', $user->id)
                  ->orWhereHas('teamMembers', fn($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn($t) => $t->where('assigned_to', $user->id))
                  ->orWhereHas('lead', fn($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                  ->orWhereHas('client', fn($c) => $c->where('account_manager', $user->id));
            })
            ->pluck('id')->all();
    }

    // GET .../tasks/{id}/activity — who created it, who it's been (re)assigned
    // to, every status change, and who marked it done, oldest first.
    public function activity(int $projectId, int $id): JsonResponse
    {
        // A Seller may read the history of a task assigned to them, and
        // nothing else — permission-free and hard-scoped, same reasoning as
        // index()'s Seller branch (they hold no canViewTasks, and the task
        // detail page loads this alongside the task itself).
        if ($this->isSeller()) {
            $project = $this->project($projectId);
            $task = $this->scopeToOwnAssignedTasks(Task::where('project_id', $project->id))->findOrFail($id);

            return ApiResponse::success($task->activities()->get());
        }

        $canViewAll = $this->can('canViewTasks');
        $canViewOwnRequests = $this->can('canCreateLinkedProjectTask');
        if (!$canViewAll && !$canViewOwnRequests) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->project($projectId);
        $task = Task::where('project_id', $project->id)->findOrFail($id);

        if (!$canViewAll) {
            if ($task->created_by !== $this->user()->id) {
                return ApiResponse::error('Permission denied', 403);
            }
        }
        // Same "own tasks only" restriction the task list itself enforces.
        elseif (!$this->isTaskManager($project) && $task->assigned_to !== $this->user()->id) {
            return ApiResponse::error('Permission denied', 403);
        }

        // Newest first — the activities() relation's own default order, so
        // the most recent action (e.g. a just-made status change) is
        // immediately visible at the top instead of buried at the bottom of
        // a long history.
        return ApiResponse::success($task->activities()->get());
    }

    // Generates a unique, human-readable task_number (e.g. PRJ-50-TASK-0001)
    // and creates the Task with it, safely under concurrent requests: the
    // project row is locked for the duration of the transaction, so a second
    // simultaneous creation on the same project blocks until the first
    // commits, and its own max(task_sequence) query then correctly sees the
    // first task's row already inserted — no two tasks on the same project
    // can ever land on the same sequence/number. Scoped to the project (not
    // company-wide) since the number already embeds the project id, which
    // is itself globally unique — no separate task_counters table needed.
    private function createTaskWithNumber(Project $project, array $attributes): Task
    {
        return DB::transaction(function () use ($project, $attributes) {
            Project::where('id', $project->id)->lockForUpdate()->first();

            $nextSequence = (int) Task::where('project_id', $project->id)->max('task_sequence') + 1;
            $attributes['task_sequence'] = $nextSequence;
            $attributes['task_number']   = sprintf('PRJ-%d-TASK-%04d', $project->id, $nextSequence);

            return Task::create($attributes);
        });
    }

    // Sellers must have zero Task creation ability, full stop — the old
    // "Client Requirement/General Request" submission path via
    // canCreateLinkedProjectTask has been retired for this role entirely
    // (see index()'s matching comment); this hard role check closes it
    // regardless of any permission a Company Admin might still hold/grant.
    public function store(Request $request, int $projectId): JsonResponse
    {
        if ($this->user()->role_type === 'seller') {
            return ApiResponse::error('Permission denied', 403);
        }

        $canFullCreate = $this->can('canCreateTasks');
        $canLinkedCreate = $this->can('canCreateLinkedProjectTask');

        if (!$canFullCreate && !$canLinkedCreate) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        if ($project->status === 'completed') {
            return ApiResponse::error('This project is completed. Reopen it before adding new tasks.', 422);
        }

        $isLinkedOnly = !$canFullCreate;

        $validated = $request->validate([
            'assigned_to'      => ['nullable', 'integer', $this->assignedToRule($project)],
            'title'            => ['required', 'string', 'max:255'],
            // See the matching note in Api\User\ProjectController — a `text`
            // column overflow guard for the now markup-formatted description.
            'description'      => ['nullable', 'string', 'max:20000'],
            'notes'            => ['nullable', 'string'],
            'priority'         => ['nullable', 'in:low,medium,high,urgent'],
            'status'           => ['nullable', 'in:todo,in_progress,review,completed,cancelled'],
            'estimated_hours'  => ['nullable', 'numeric', 'min:0'],
            'start_date'       => ['nullable', 'date'],
            'due_date'         => ['nullable', 'date'],
            'task_type'        => ['nullable', 'in:general,production,client_request,internal'],
        ]);

        if ($isLinkedOnly) {
            if (in_array($validated['task_type'] ?? null, ['production', 'internal'], true)) {
                return ApiResponse::error('Only Client Requirement or General Request tasks can be submitted here.', 403);
            }
            $validated['task_type'] = $validated['task_type'] ?? 'client_request';
            // "Pending PM Review" — a Project Manager reviews and converts
            // this via the normal update() endpoint (canEditTasks already
            // lets them change type/status/assignee freely).
            $validated['status'] = 'review';
        }

        // Assigning the new task to someone else at creation time requires the
        // dedicated canAssignTasks permission — canCreateTasks/
        // canCreateLinkedProjectTask alone only cover creating a task (which
        // defaults to unassigned/self). A Seller without canAssignTasks can
        // never hand a request straight to a Production/Dev/Design/QA user.
        $assignee = $validated['assigned_to'] ?? null;
        if ($assignee && $assignee !== $this->user()->id && !$this->can('canAssignTasks')) {
            return ApiResponse::error('Permission denied: cannot assign tasks to other users', 403);
        }

        $isProduction = ($validated['task_type'] ?? null) === 'production';

        $validated['project_id']          = $project->id;
        $validated['created_by']          = $this->user()->id;
        $validated['assigned_by']         = ($validated['assigned_to'] ?? null) ? $this->user()->id : null;
        $validated['status']            ??= 'todo';
        $validated['priority']          ??= 'medium';
        $validated['task_type']         ??= 'general';
        $validated['is_production_task']  = $isProduction;

        $task = $this->createTaskWithNumber($project, $validated);

        if ($task->assigned_to) {
            Notification::create([
                'user_id'    => $task->assigned_to,
                'company_id' => $project->company_id,
                'type'       => 'task_assigned',
                'title'      => 'New task assigned',
                'body'       => "You were assigned task {$task->task_number} - \"{$task->title}\" on \"{$project->name}\".",
                'data'       => ['project_id' => $project->id, 'task_id' => $task->id, 'link' => "/projects/{$project->id}/tasks/{$task->id}"],
            ]);
            \App\Services\ProjectChatService::addTaskAssignee($project, $task->assigned_to);
            // Email alongside the bell notification — non-blocking, see
            // AssignmentMailService. Passing the actor keeps it from mailing
            // someone who just created a task for themselves (the in-app
            // notification above deliberately still fires in that case).
            \App\Services\AssignmentMailService::taskAssigned($task, $project, $this->userName(), $this->user()->id);
        }

        $this->logActivity($project->company_id, 'task_created', 'Task', $task->id, $validated);
        if ($task->assigned_to) {
            $this->logActivity($project->company_id, 'task_assigned', 'Task', $task->id, ['assigned_to' => $task->assigned_to]);
        }

        $task->logActivity('created', "Task \"{$task->title}\" created", $this->userName());
        if ($task->assigned_to) {
            $assigneeName = User::find($task->assigned_to)?->name ?? 'someone';
            $task->logActivity('assigned', "Task assigned to {$assigneeName}", $this->userName(), ['to' => $task->assigned_to]);
        }

        return ApiResponse::success($task->fresh(['assignedTo', 'productionAssignedTo', 'assignedBy']), 'Task created', 201);
    }

    public function update(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($projectId);
        $task = Task::where('project_id', $project->id)->findOrFail($id);

        // A Seller may only ever touch a task actually assigned to them
        // (2026-09-04: they are assignable now). What they may then DO is
        // already narrow without any extra branch here: they hold no
        // canEditTasks, so the detail-field refusal below rejects any field
        // payload; they're in NON_REASSIGNING_ROLES, so assigned_to is closed;
        // what's left is progressing their own task's status and commenting.
        if ($this->isSeller() && $task->assigned_to !== $this->user()->id) {
            return ApiResponse::error('Permission denied', 403);
        }

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $isOwnTask = $task->assigned_to === $this->user()->id;
        $isPmTier  = $this->isTaskManager($project);
        // Editing a task's DETAIL fields is permission-only — no $isPmTier
        // bypass (2026-09-02): "Task Edit" has to be a right a Company Admin
        // can actually assign/revoke role-wise (see the pm_edit_tasks
        // checkbox in frontend/lib/simplifiedProjectPermissions.ts), and a
        // tier bypass made revoking it a no-op for a Project Manager, the
        // project's own project_manager_id, and anyone holding
        // canViewAllCompanyProjects (e.g. Lead Manager). Company Admin is
        // unaffected — the admin guard bypasses every sub-user permission
        // check. Status changes and reassignment deliberately still keep
        // their own tier/role paths below ($canAssign, $isQaRole,
        // TaskStatusService), so revoking Task Edit narrows field editing
        // only.
        $canEdit   = $this->can('canEditTasks');
        $canAssign = $isPmTier || $this->can('canAssignTasks');
        $isQaRole  = $this->user()->role_type === 'qa';
        $canOverrideTaskStatus = $this->can('canOverrideTaskStatus');
        // Any team member of this specific project (added via "Manage
        // Team") can reassign ANY of the project's tasks to any other valid
        // teammate or themselves — not just their own task, and not gated
        // behind canAssignTasks/canEditTasks. Title/description/etc. below
        // still require $canEdit; this only ever widens the assigned_to path.
        $isTeamMember = $project->teamMembers()->where('user_id', $this->user()->id)->exists();
        // A QA user is neither the assignee nor necessarily granted
        // canEditTasks/canAssignTasks, but must still be able to reach the
        // status-only path below (a free jump to any status) — gated for
        // real by TaskStatusService::canChangeTaskStatus() further down, not
        // here. $rules only adds title/assigned_to when $canEdit/$canAssign
        // are true, so this broadened gate still can't let a QA-only user
        // touch anything but status/comment.
        if (!$isOwnTask && !$canEdit && !$canAssign && !$isQaRole && !$canOverrideTaskStatus && !$isTeamMember) {
            return ApiResponse::error('Permission denied', 403);
        }

        // Without Task Edit, a detail-field payload is refused outright
        // instead of being silently dropped by the $rules build below — a
        // revoked permission must read as "Permission denied", not as a save
        // that quietly did nothing. Status/assignee/comment/production
        // handoff are unaffected (their own gates above).
        if (!$canEdit && $request->hasAny(['title', 'description', 'notes', 'priority', 'start_date', 'due_date', 'estimated_hours'])) {
            return ApiResponse::error('Permission denied: you cannot edit task details', 403);
        }

        $rules = [
            'status'                 => ['sometimes', 'in:' . implode(',', Task::ALL_STATUSES)],
            'comment'                => ['nullable', 'string', 'max:1000'],
            // Optional Production/Deployment handoff when moving to "Ready
            // for Production" — any non-seller, non-client project team
            // member, same rule as assigned_to (not narrowed to role_type='production',
            // since a developer/designer can just as validly own the
            // production step).
            'production_assigned_to' => ['nullable', 'integer', $this->assignedToRule($project)],
        ];
        if ($canEdit) {
            $rules += [
                'title'           => ['sometimes', 'string', 'max:255'],
                'description'     => ['nullable', 'string', 'max:20000'],
                'notes'           => ['nullable', 'string'],
                'priority'        => ['sometimes', 'in:low,medium,high,urgent'],
                'start_date'      => ['nullable', 'date'],
                'due_date'        => ['nullable', 'date'],
                // Same field set store() accepts and Admin\TaskController::
                // update() already allowed — without it the Edit Task form
                // could show Estimated Hours but never save it.
                'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            ];
        }
        // Reassigning is allowed with either canEditTasks or the dedicated
        // canAssignTasks permission — a PM can be granted assign rights
        // without full task-editing rights. The $isOwnTask/$isTeamMember
        // paths additionally widen it past any permission, for the roles
        // still allowed to reassign at all.
        //
        // NON_REASSIGNING_ROLES (Developer/Designer/Production/Team Member/
        // Seller) can never move a task to someone else — 2026-09-04 request:
        // "a developer cannot assign or reassign a task to another
        // developer". This has to be a ROLE check, not just a revoked
        // canAssignTasks: those two widening paths are permission-free, and a
        // worker is virtually always $isTeamMember on the project their own
        // task lives on, so dropping the permission alone left reassignment
        // wide open for them.
        $canReassign = !in_array($this->user()->role_type, self::NON_REASSIGNING_ROLES, true)
            && ($canEdit || $canAssign || $isOwnTask || $isTeamMember);

        if ($canReassign) {
            $rules['assigned_to'] = ['nullable', 'integer', $this->assignedToRule($project)];
        } elseif ($request->has('assigned_to')) {
            // Refused outright rather than silently dropped by the $rules
            // build — same "a closed door must say so" reasoning as the
            // detail-field check above. The frontend already renders the
            // assignee read-only for these roles; this covers a direct API
            // call (and a stale open tab).
            return ApiResponse::error('Permission denied: you cannot change who a task is assigned to', 403);
        }

        $validated = $request->validate($rules);

        $wasAssignee = $task->assigned_to;
        $wasStatus   = $task->status;
        // Snapshot of every other trackable field, for the generic "updated"
        // History entry below — assigned_to/status get their own dedicated
        // entry types instead, so they're deliberately excluded here.
        $trackedFields = ['title', 'description', 'notes', 'priority', 'start_date', 'due_date', 'estimated_hours'];
        $wasValues = collect($trackedFields)->mapWithKeys(fn ($f) => [$f => $task->$f]);

        if (isset($validated['assigned_to']) && $validated['assigned_to'] !== $wasAssignee) {
            $validated['assigned_by'] = $this->user()->id;
        }

        $comment = $validated['comment'] ?? null;
        unset($validated['comment']);

        $productionAssignedToProvided = array_key_exists('production_assigned_to', $validated);
        $productionAssignedTo = $validated['production_assigned_to'] ?? null;
        unset($validated['production_assigned_to']);

        $newStatus = $validated['status'] ?? null;
        $statusChanging = $newStatus !== null && $newStatus !== $wasStatus;
        unset($validated['status']);

        // Standalone Production handoff — the listing's dedicated dropdown
        // lets an actor (re)assign who owns the production step without
        // necessarily changing status in the same request. When the status
        // IS changing into ready_for_production here,
        // TaskStatusService::applyTransition() below already owns stamping
        // this column (plus its own notification) — skip merging here so
        // that path doesn't double-write/double-notify.
        $wasProductionAssignedTo = $task->production_assigned_to;
        $productionHandoffStandalone = $productionAssignedToProvided && !($statusChanging && $newStatus === 'ready_for_production');
        if ($productionHandoffStandalone) $validated['production_assigned_to'] = $productionAssignedTo;

        if ($statusChanging) {
            $actor = [
                'type'        => 'user',
                'id'          => $this->user()->id,
                'name'        => $this->userName(),
                'is_pm'       => $isPmTier,
                'is_assignee' => $isOwnTask,
                'role_type'   => $this->user()->role_type,
                'perms'       => $canOverrideTaskStatus ? ['canOverrideTaskStatus'] : [],
            ];

            if (!\App\Services\TaskStatusService::canChangeTaskStatus($task, $actor)) {
                return ApiResponse::error("You don't have permission to change this task's status.", 422);
            }
        }

        $task->update($validated);

        if ($statusChanging) {
            \App\Services\TaskStatusService::applyTransition($task, $newStatus, $comment, $actor, $productionAssignedTo);
        }

        if (isset($validated['assigned_to']) && $validated['assigned_to'] !== $wasAssignee) {
            $this->logActivity($project->company_id, 'task_assigned', 'Task', $task->id, ['assigned_to' => $validated['assigned_to']]);

            $oldName = $wasAssignee ? (User::find($wasAssignee)?->name ?? 'someone') : null;
            $newName = $validated['assigned_to'] ? (User::find($validated['assigned_to'])?->name ?? 'someone') : null;
            $description = $newName
                ? ($oldName ? "Task reassigned from {$oldName} to {$newName}" : "Task assigned to {$newName}")
                : "Task unassigned from {$oldName}";
            $task->logActivity('assigned', $description, $this->userName(), ['from' => $wasAssignee, 'to' => $validated['assigned_to']]);

            if ($validated['assigned_to'] && $validated['assigned_to'] !== $this->user()->id) {
                Notification::create([
                    'user_id'    => $validated['assigned_to'],
                    'company_id' => $project->company_id,
                    'type'       => 'task_assigned',
                    'title'      => 'Task assigned to you',
                    'body'       => "You were assigned task {$task->task_number} - \"{$task->title}\" on \"{$project->name}\".",
                    'data'       => ['project_id' => $project->id, 'task_id' => $task->id, 'link' => "/projects/{$project->id}/tasks/{$task->id}"],
                ]);
                // $task already carries the new assignee — update() above ran
                // before this block.
                \App\Services\AssignmentMailService::taskAssigned($task, $project, $this->userName(), $this->user()->id);
            }
            if ($validated['assigned_to']) {
                \App\Services\ProjectChatService::addTaskAssignee($project, $validated['assigned_to']);
            }
            if ($wasAssignee) {
                \App\Services\ProjectChatService::removeParticipantIfNoLongerEligible($project, $wasAssignee);
            }
        }

        if ($productionHandoffStandalone && $productionAssignedTo !== $wasProductionAssignedTo) {
            $this->logActivity($project->company_id, 'task_production_assigned', 'Task', $task->id, ['production_assigned_to' => $productionAssignedTo]);

            $oldName = $wasProductionAssignedTo ? (User::find($wasProductionAssignedTo)?->name ?? 'someone') : null;
            $newName = $productionAssignedTo ? (User::find($productionAssignedTo)?->name ?? 'someone') : null;
            $description = $newName
                ? ($oldName ? "Production handoff reassigned from {$oldName} to {$newName}" : "Task handed to {$newName} for Production")
                : "Production handoff removed from {$oldName}";
            $task->logActivity('status_changed', $description, $this->userName(), ['from' => $wasProductionAssignedTo, 'to' => $productionAssignedTo]);

            if ($productionAssignedTo && $productionAssignedTo !== $this->user()->id) {
                Notification::create([
                    'user_id'    => $productionAssignedTo,
                    'company_id' => $project->company_id,
                    'type'       => 'task_ready_for_production',
                    'title'      => 'Task handed to you for Production',
                    'body'       => "You were handed task {$task->task_number} - \"{$task->title}\" for Production.",
                    'data'       => ['project_id' => $project->id, 'task_id' => $task->id, 'link' => "/projects/{$project->id}/tasks/{$task->id}"],
                ]);
            }
        }

        if ($statusChanging) {
            $this->logActivity($project->company_id, 'task_status_updated', 'Task', $task->id, ['status' => $newStatus]);

            // Activity logging + PM/QA/assignee/Company-Admin notifications
            // for this transition are entirely handled by
            // TaskStatusService::applyTransition() above.
        }

        // Generic "updated" History entry for any other field edit
        // (title/description/notes/priority/start_date/due_date/
        // estimated_hours) — assigned_to/status changes already got their own
        // dedicated entry above, so they're excluded from $trackedFields.
        $changedFields = collect($trackedFields)->filter(fn ($f) => array_key_exists($f, $validated) && $validated[$f] != $wasValues[$f]);
        if ($changedFields->isNotEmpty()) {
            $labels = [
                'title' => 'title', 'description' => 'description', 'notes' => 'notes',
                'priority' => 'priority', 'start_date' => 'start date', 'due_date' => 'due date',
                'estimated_hours' => 'estimated hours',
            ];
            $changedLabels = $changedFields->map(fn ($f) => $labels[$f] ?? $f)->implode(', ');
            $task->logActivity('updated', "{$this->userName()} updated {$changedLabels}", $this->userName(), ['fields' => $changedFields->values()->all()]);
        }

        return ApiResponse::success($task->fresh(['assignedTo', 'assignedBy', 'productionAssignedTo']), 'Task updated');
    }
}
