<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Models\Notification;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()   { return auth('admin')->user(); }
    // trim()->? , not `?? 'Admin'` — a blank/whitespace-only name would
    // otherwise pass through and leave a History entry with an empty actor.
    private function adminName(): string { return trim((string) ($this->admin()->name ?? '')) ?: 'Admin'; }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // Production/general tasks can only be assigned to a real user of this
    // admin's own company/companies — not any user id in the system. A Client
    // portal account can never be a task assignee; a Seller now CAN be
    // (2026-09-04 request — a PM hands work/follow-up straight to the deal's
    // Seller), and so can a Project Manager genuinely on the project's team.
    // Matches Api\User\TaskController::assignedToRule().
    private function assignedToRule()
    {
        // Rule::exists()->where() only supports 2-arg (column, value) equality
        // — a 3-arg (column, operator, value) call silently misparses, so a
        // closure is required for a "!=" condition.
        return Rule::exists('users', 'id')->whereIn('company_id', $this->companyIds())
            ->where(fn ($query) => $query->whereNotIn('role_type', ['client']));
    }

    private function project(int $projectId): Project
    {
        return Project::whereIn('company_id', $this->companyIds())->findOrFail($projectId);
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        // project:id,name,company_id — the task DETAIL page loads a single task
        // out of this list (adminProjectService.tasks.list() → find by id), so
        // without the relation its "Project" field had nothing to read and
        // always rendered "—". indexAll() already eager-loads it for the same
        // reason.
        $q = Task::where('project_id', $project->id)->with(['assignedTo:id,name', 'productionAssignedTo:id,name', 'assignedBy:id,name', 'project:id,name,company_id'])->withCount('attachments');

        if ($request->filled('status'))      $q->where('status', $request->status);
        if ($request->filled('priority'))    $q->where('priority', $request->priority);
        if ($request->filled('assigned_to')) $q->where('assigned_to', $request->assigned_to);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('task_number', 'like', "%{$s}%"));
        }

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    /**
     * Cross-project task list for the whole company — the nested index()
     * above requires a project id, but the sidebar's "Tasks" view needs
     * every task across every project the admin's companies own.
     */
    public function indexAll(Request $request): JsonResponse
    {
        // Company-Wise Dashboard Filtering — scoped to the active company.
        $companyIds = $this->activeCompanyIds();

        // project.teamMembers.user is here so the frontend's "Assigned To"
        // reassignment picker can scope its options to this task's own
        // project team, instead of every user in the company (same fix
        // already applied to the Projects listing's PM dropdown).
        $q = Task::whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->with(['assignedTo:id,name', 'productionAssignedTo:id,name', 'assignedBy:id,name',
                'project:id,name,company_id', 'project.teamMembers.user:id,name,role_type,custom_role_label'])
            ->withCount('attachments');

        if ($request->filled('status'))      $q->where('status', $request->status);
        if ($request->filled('priority'))    $q->where('priority', $request->priority);
        if ($request->filled('assigned_to')) $q->where('assigned_to', $request->assigned_to);
        if ($request->filled('project_id'))  $q->where('project_id', $request->project_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('task_number', 'like', "%{$s}%"));
        }

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    // GET /admin/tasks/{id}/lookup — resolves a bare task id to its
    // project_id for the guard-agnostic /task/{id} share-link redirector.
    // Company-scoped only; the destination Admin task page does its own
    // full permission check once it has a project_id to load.
    public function lookup(int $id): JsonResponse
    {
        $task = Task::whereHas('project', fn ($q) => $q->whereIn('company_id', $this->companyIds()))
            ->findOrFail($id);

        return ApiResponse::success(['project_id' => $task->project_id]);
    }

    // GET .../tasks/{id}/activity — who created it, who it's been (re)assigned
    // to, every status change, and who marked it done, newest first.
    public function activity(int $projectId, int $id): JsonResponse
    {
        $project = $this->project($projectId);
        $task = Task::where('project_id', $project->id)->findOrFail($id);

        // Newest first — the activities() relation's own default order.
        return ApiResponse::success($task->activities()->get());
    }

    // Mirrors Api\User\TaskController::createTaskWithNumber() — generates a
    // unique task_number (e.g. PRJ-50-TASK-0001) and creates the Task with
    // it, safely under concurrent requests via a project-row lock for the
    // duration of the transaction. See that method's comment for the full
    // reasoning.
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

    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        if ($project->status === 'closed') {
            return ApiResponse::error('This project is closed and read-only. Reopen it first to make changes.', 422);
        }

        if ($project->status === 'completed') {
            return ApiResponse::error('This project is completed. Reopen it before adding new tasks.', 422);
        }

        $validated = $request->validate([
            'parent_task_id'   => ['nullable', 'integer', 'exists:tasks,id'],
            'assigned_to'      => ['nullable', 'integer', $this->assignedToRule()],
            'title'            => ['required', 'string', 'max:255'],
            // See the matching note in Api\User\ProjectController — a `text`
            // column overflow guard for the now markup-formatted description.
            'description'      => ['nullable', 'string', 'max:20000'],
            'status'           => ['nullable', 'in:todo,in_progress,review,completed,cancelled'],
            'priority'         => ['nullable', 'in:low,medium,high,urgent'],
            'estimated_hours'  => ['nullable', 'numeric', 'min:0'],
            'start_date'       => ['nullable', 'date'],
            'due_date'         => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
            'task_type'        => ['nullable', 'in:general,production,client_request,internal'],
        ]);

        $isProduction = ($validated['task_type'] ?? null) === 'production';
        $validated['task_type']         ??= 'general';
        $validated['is_production_task'] = $isProduction;

        $validated['project_id'] = $project->id;
        // created_by FKs to `users` (staff), not `company_admins` — leave null
        // when the creator is the Company Admin rather than a staff member.
        $validated['created_by'] = null;
        $validated['status']   ??= 'todo';
        $validated['priority'] ??= 'medium';

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
            // AssignmentMailService. No actor id: a Company Admin isn't a
            // `users` row, so they can never be the assignee themselves.
            \App\Services\AssignmentMailService::taskAssigned($task, $project, $this->adminName());
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => 'created',
            'module_key'  => 'project_management',
            'entity_type' => 'Task',
            'entity_id'   => $task->id,
            'new_values'  => $validated,
        ]);

        $task->logActivity('created', "Task \"{$task->title}\" created", $this->adminName());
        if ($task->assigned_to) {
            $assigneeName = User::find($task->assigned_to)?->name ?? 'someone';
            $task->logActivity('assigned', "Task assigned to {$assigneeName}", $this->adminName(), ['to' => $task->assigned_to]);
        }

        return ApiResponse::success($task->fresh(['assignedTo', 'productionAssignedTo', 'assignedBy']), 'Task created', 201);
    }

    public function update(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($projectId);
        $task = Task::where('project_id', $project->id)->findOrFail($id);

        if ($project->status === 'closed') {
            return ApiResponse::error('This project is closed and read-only. Reopen it first to make changes.', 422);
        }

        $validated = $request->validate([
            'assigned_to'      => ['nullable', 'integer', $this->assignedToRule()],
            'title'            => ['sometimes', 'string', 'max:255'],
            // See the matching note in Api\User\ProjectController — a `text`
            // column overflow guard for the now markup-formatted description.
            'description'      => ['nullable', 'string', 'max:20000'],
            'status'           => ['sometimes', 'in:' . implode(',', Task::ALL_STATUSES)],
            'comment'          => ['nullable', 'string', 'max:1000'],
            'production_assigned_to' => ['nullable', 'integer', $this->assignedToRule()],
            'priority'         => ['sometimes', 'in:low,medium,high,urgent'],
            'estimated_hours'  => ['nullable', 'numeric', 'min:0'],
            'start_date'       => ['nullable', 'date'],
            'due_date'         => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
            'progress'         => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $wasAssignee = $task->assigned_to;
        $wasStatus   = $task->status;
        // Snapshot of every other trackable field, for the generic "updated"
        // History entry below — assigned_to/status get their own dedicated
        // entry types instead, so they're deliberately excluded here.
        $trackedFields = ['title', 'description', 'priority', 'due_date', 'notes', 'estimated_hours', 'start_date'];
        $wasValues = collect($trackedFields)->mapWithKeys(fn ($f) => [$f => $task->$f]);

        $comment = $validated['comment'] ?? null;
        unset($validated['comment']);

        $productionAssignedTo = $validated['production_assigned_to'] ?? null;
        unset($validated['production_assigned_to']);

        $newStatus = $validated['status'] ?? null;
        $statusChanging = $newStatus !== null && $newStatus !== $wasStatus;
        unset($validated['status']);

        // Admin has no permission gate on this guard today (any Company
        // Admin who owns the project can change any status) — kept as-is,
        // matching "Company Admin: can change any task status, always".
        $actor = ['type' => 'admin', 'id' => $this->admin()->id, 'name' => $this->adminName(), 'is_pm' => false, 'is_assignee' => false, 'perms' => []];

        if ($statusChanging && !\App\Services\TaskStatusService::canChangeTaskStatus($task, $actor)) {
            return ApiResponse::error("You don't have permission to change this task's status.", 422);
        }

        $task->update($validated);

        if ($statusChanging) {
            \App\Services\TaskStatusService::applyTransition($task, $newStatus, $comment, $actor, $productionAssignedTo);
        }

        if (isset($validated['assigned_to']) && $validated['assigned_to'] !== $wasAssignee) {
            // Notification.user_id is NOT NULL — unassigning (new value null,
            // e.g. via the All Tasks listing's "Unassigned" option) must
            // never reach Notification::create, or it throws a DB constraint
            // violation.
            if ($validated['assigned_to']) {
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
                \App\Services\AssignmentMailService::taskAssigned($task, $project, $this->adminName());
            }

            $oldName = $wasAssignee ? (User::find($wasAssignee)?->name ?? 'someone') : null;
            $newName = $validated['assigned_to'] ? (User::find($validated['assigned_to'])?->name ?? 'someone') : null;
            $description = $newName
                ? ($oldName ? "Task reassigned from {$oldName} to {$newName}" : "Task assigned to {$newName}")
                : "Task unassigned from {$oldName}";
            $task->logActivity('assigned', $description, $this->adminName(), ['from' => $wasAssignee, 'to' => $validated['assigned_to']]);

            if ($validated['assigned_to']) {
                \App\Services\ProjectChatService::addTaskAssignee($project, $validated['assigned_to']);
            }
            if ($wasAssignee) {
                \App\Services\ProjectChatService::removeParticipantIfNoLongerEligible($project, $wasAssignee);
            }
        }

        // Status-change activity logging + notifications (PM/QA/assignee/
        // Company Admins per transition) are entirely handled by
        // TaskStatusService::applyTransition() above — including the
        // "completed" case, which now notifies the assignee (not the
        // creator) to match the rest of the pipeline's notification rules
        // and avoid a duplicate row when creator === assignee.

        // Note: co-Admins don't get a separate real-notification for a plain
        // non-QA-pipeline status change here — this method's own
        // SystemAuditLog::create() below already covers every status change
        // in Admin's merged bell feed; adding one here too would
        // double-notify for the same event.

        // Generic "updated" History entry for any other field edit
        // (title/description/priority/due_date/notes/estimated_hours/
        // start_date) — assigned_to/status changes already got their own
        // dedicated entry above, so they're excluded from $trackedFields.
        $changedFields = collect($trackedFields)->filter(fn ($f) => array_key_exists($f, $validated) && $validated[$f] != $wasValues[$f]);
        if ($changedFields->isNotEmpty()) {
            $labels = [
                'title' => 'title', 'description' => 'description', 'priority' => 'priority',
                'due_date' => 'due date', 'notes' => 'notes', 'estimated_hours' => 'estimated hours', 'start_date' => 'start date',
            ];
            $changedLabels = $changedFields->map(fn ($f) => $labels[$f] ?? $f)->implode(', ');
            $task->logActivity('updated', "{$this->adminName()} updated {$changedLabels}", $this->adminName(), ['fields' => $changedFields->values()->all()]);
        }

        // Classify the action so the admin's own notification bell (which
        // reads these rows directly) shows a specific, useful title instead
        // of a generic "Updated" for every kind of task edit.
        $action = 'task_updated';
        if (isset($validated['assigned_to']) && $validated['assigned_to'] !== $wasAssignee) {
            $action = 'task_assigned';
        } elseif ($statusChanging) {
            $action = 'task_status_updated';
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => $action,
            'module_key'  => 'project_management',
            'entity_type' => 'Task',
            'entity_id'   => $task->id,
            'new_values'  => $statusChanging ? array_merge($validated, ['status' => $newStatus]) : $validated,
        ]);

        return ApiResponse::success($task->fresh(['assignedTo', 'productionAssignedTo', 'assignedBy']), 'Task updated');
    }

    public function destroy(int $projectId, int $id): JsonResponse
    {
        $project = $this->project($projectId);
        $task = Task::where('project_id', $project->id)->findOrFail($id);

        if ($project->status === 'closed') {
            return ApiResponse::error('This project is closed and read-only. Reopen it first to make changes.', 422);
        }

        $task->delete();

        return ApiResponse::success(null, 'Task deleted');
    }
}
