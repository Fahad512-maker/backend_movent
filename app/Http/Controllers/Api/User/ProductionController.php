<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Deliverable;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Revision;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionController extends Controller
{
    private function user() { return auth('sanctum')->user(); }
    private function userName(): string { return trim((string) ($this->user()->name ?? '')) ?: 'User'; }

    // Surfaces a production/deliverable/revision event in the task's own
    // History feed (not just SystemAuditLog, which the logActivity() below
    // already writes to for company-wide audit purposes) — "who fixed it"
    // per the task activity log. A no-op when there's no task (a
    // project-level deliverable has nowhere to log this).
    private function logTaskRevision(?int $taskId, string $description): void
    {
        if (!$taskId) return;
        Task::find($taskId)?->logActivity('revision', $description, $this->userName());
    }

    // Real-time notification to this task's project PM — PM has no equivalent
    // of Admin's SystemAuditLog-based feed, so production start/submit and
    // revision-resolved events (which only ever wrote to the audit log/task
    // History before) were previously invisible to PM until they happened to
    // check the task page. Self-skipped automatically if the PM is the actor.
    private function notifyPm(Task $task, string $type, string $title, string $message): void
    {
        $projectManagerId = $task->project?->project_manager_id;
        if (!$projectManagerId) return;

        \App\Services\NotificationService::send([
            'company_id'        => $task->project->company_id,
            'recipient_user_id' => $projectManagerId,
            'actor_user_id'     => $this->user()->id,
            'module'            => 'project_management',
            'type'              => $type,
            'title'             => $title,
            'message'           => $message,
            'entity_type'       => 'Task',
            'entity_id'         => $task->id,
            'url'               => "/projects/{$task->project_id}/tasks/{$task->id}",
        ]);
    }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
    }

    private function logActivity(int $companyId, string $action, string $entityType, int $entityId, array $newValues = []): void
    {
        SystemAuditLog::create([
            'company_id' => $companyId, 'user_id' => $this->user()->id,
            'action' => $action, 'module_key' => 'project_management',
            'entity_type' => $entityType, 'entity_id' => $entityId, 'new_values' => $newValues,
        ]);
    }

    // Shared by uploadDeliverable/approve/reject/requestRevision — skips
    // notifying the actor about their own action (e.g. a PM who is also the
    // uploader wouldn't get pinged about their own submission).
    private function notifyDeliverableEvent(Deliverable $deliverable, ?int $recipientId, int $actorId, string $type, string $title, string $body): void
    {
        if (!$recipientId || $recipientId === $actorId) return;

        Notification::create([
            'user_id'    => $recipientId,
            'company_id' => $deliverable->project->company_id,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'data'       => ['deliverable_id' => $deliverable->id, 'project_id' => $deliverable->project_id, 'link' => "/projects/{$deliverable->project_id}"],
        ]);
    }

    // Auto-derived task progress for a Deliverable/Revision outcome — mirrors
    // Admin\ProductionController::progressForStatus() (each controller owns
    // its own helpers per this codebase's convention).
    private function progressForStatus(string $status): int
    {
        return match ($status) {
            'revision_requested' => 50,
            'approved'           => 90,
            'rejected'           => 25,
            default              => 0,
        };
    }

    // Mirrors Api\User\ProjectController::visibleProjects(), ids only.
    private function visibleProjectIds(): array
    {
        $user = $this->user();
        $base = Project::where('company_id', $user->company_id);

        if ($this->can('canViewAllCompanyProjects')) {
            return $base->pluck('id')->all();
        }

        return $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhereHas('teamMembers', fn($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn($t) => $t->where('assigned_to', $user->id));
            })
            ->pluck('id')->all();
    }

    // Mirrors the visibility scope in Api\User\ProjectController::visibleProjects().
    private function visibleProject(int $projectId): Project
    {
        $user = $this->user();

        $base = Project::where('company_id', $user->company_id);

        if ($this->can('canViewAllCompanyProjects')) {
            return $base->findOrFail($projectId);
        }

        return $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhereHas('teamMembers', fn($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn($t) => $t->where('assigned_to', $user->id));
            })
            ->findOrFail($projectId);
    }

    // GET — list deliverables for a visible project (no read endpoint existed before).
    public function deliverables(int $projectId): JsonResponse
    {
        if (!$this->can('canViewDeliverables')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProject($projectId);

        $deliverables = Deliverable::where('project_id', $project->id)
            ->with(['uploadedBy:id,name', 'task:id,title'])
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($deliverables);
    }

    public function uploadDeliverable(Request $request, int $projectId): JsonResponse
    {
        if (!$this->can('canUploadDeliverables')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();

        $validated = $request->validate([
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'file'    => ['required', 'file', 'max:51200'],
            'title'   => ['required', 'string', 'max:255'],
        ]);

        // Unchanged from before this fix — upload scope stays company-wide,
        // not narrowed to visibleProject(), to avoid altering existing behavior.
        $project = Project::where('company_id', $user->company_id)->findOrFail($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $file = $request->file('file');

        $stored = AttachmentStorageService::store($file, $project->company_id, 'project_delivery', $project->storage_folder . '/deliverables');
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        // 'delivered' now represents the post-approval terminal state (see
        // approve()) — a fresh upload is awaiting review, so it starts as
        // 'submitted' instead. version increments per task so re-submissions
        // after a revision request are distinguishable.
        $version = ($validated['task_id'] ?? null)
            ? (Deliverable::where('task_id', $validated['task_id'])->max('version') + 1)
            : 1;

        $deliverable = Deliverable::create(array_merge($stored->locationAttributes(), [
            'project_id'      => $project->id,
            'task_id'         => $validated['task_id'] ?? null,
            'uploaded_by'     => $user->id,
            'title'           => $validated['title'],
            'file_name'       => $stored->originalName,
            'file_type'       => $file->getClientOriginalExtension(),
            'file_size_bytes' => $stored->sizeBytes,
            'status'          => 'submitted',
            'version'         => $version,
            'submitted_at'    => now(),
        ]));

        $this->logActivity($project->company_id, 'deliverable_uploaded', $deliverable->task_id ? 'Task' : 'Project', $deliverable->task_id ?? $project->id, ['deliverable_id' => $deliverable->id, 'version' => $version]);
        $this->logTaskRevision($deliverable->task_id, "{$this->userName()} uploaded deliverable \"{$deliverable->title}\" (v{$version})");

        $submittedTask = $deliverable->task_id ? Task::find($deliverable->task_id) : null;
        $taskTitle = $submittedTask?->title;
        $taskNumber = $submittedTask?->task_number;
        $this->notifyDeliverableEvent(
            $deliverable, $project->project_manager_id, $user->id,
            'deliverable_submitted', 'Deliverable submitted for review',
            $taskTitle
                ? "{$user->name} submitted deliverable for task {$taskNumber} - \"{$taskTitle}\" in project \"{$project->name}\"."
                : "{$user->name} submitted a deliverable in project \"{$project->name}\"."
        );

        return ApiResponse::success($deliverable, 'Deliverable uploaded', 201);
    }

    public function resolveRevision(int $id): JsonResponse
    {
        if (!$this->can('canResolveRevisions')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();

        $revision = Revision::whereHas('deliverable.project', fn($p) => $p->where('company_id', $user->company_id))
            ->findOrFail($id);

        if ($revision->deliverable?->project?->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $revision->update(['status' => 'resolved', 'resolved_at' => now()]);

        $deliverable = $revision->deliverable;
        $this->logActivity($deliverable->project->company_id, 'revision_resolved', $deliverable->task_id ? 'Task' : 'Project', $deliverable->task_id ?? $deliverable->project_id, ['revision_id' => $revision->id]);
        $this->logTaskRevision($deliverable->task_id, "{$this->userName()} fixed/resolved the requested revision");

        if ($deliverable->task_id && ($task = Task::find($deliverable->task_id))) {
            $this->notifyPm($task, 'revision_resolved', 'Revision resolved', "{$this->userName()} resolved the requested revision for task '{$task->task_number} - {$task->title}'.");
        }

        return ApiResponse::success($revision, 'Revision resolved');
    }

    // Project Manager (staff) requests a revision on a submitted deliverable.
    public function requestRevision(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canCreateRevisions')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();

        $deliverable = Deliverable::whereHas('project', fn($p) => $p->where('company_id', $user->company_id))
            ->findOrFail($id);

        if ($deliverable->project?->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        // QA Failed / Revision Required requires a reason when it drives a
        // linked task's status — a project-level deliverable (no task_id)
        // keeps the feedback optional as before.
        $validated = $request->validate([
            'feedback' => [$deliverable->task_id ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);

        $revision = Revision::create([
            'deliverable_id' => $deliverable->id,
            'requested_by'   => $user->id,
            'feedback'       => $validated['feedback'] ?? null,
            'status'         => 'open',
        ]);

        $deliverable->update(['status' => 'revision_requested']);

        // Cosmetic task-progress bookkeeping only — Deliverable/Revision
        // actions never touch Task.status (see TaskStatusService); status
        // changes are a separate, explicit action by Developer/QA/PM/Admin.
        if ($deliverable->task_id) {
            Task::where('id', $deliverable->task_id)->update(['progress' => $this->progressForStatus('revision_requested')]);
        }

        $this->logActivity($deliverable->project->company_id, 'revision_requested', $deliverable->task_id ? 'Task' : 'Project', $deliverable->task_id ?? $deliverable->project_id, ['deliverable_id' => $deliverable->id, 'revision_id' => $revision->id]);
        $this->logTaskRevision($deliverable->task_id, "{$this->userName()} requested a revision" . (!empty($validated['feedback']) ? ": " . \Illuminate\Support\Str::limit($validated['feedback'], 80) : ''));

        $taskTitle = $deliverable->task?->title;
        $taskNumber = $deliverable->task?->task_number;
        $this->notifyDeliverableEvent(
            $deliverable, $deliverable->uploaded_by, $user->id,
            'revision_requested', 'Revision requested',
            $taskTitle
                ? "{$user->name} requested revision on task {$taskNumber} - \"{$taskTitle}\" in project \"{$deliverable->project->name}\"."
                : "{$user->name} requested revision on your deliverable in project \"{$deliverable->project->name}\"."
        );

        return ApiResponse::success($revision->load('requestedBy:id,name'), 'Revision requested', 201);
    }

    // PM approves a submitted deliverable — updates the Deliverable's own
    // status and the task's cosmetic progress percentage only. Task.status
    // itself is untouched (see TaskStatusService) — moving the task forward
    // (e.g. to Ready for Production) is a separate, explicit action.
    public function approve(int $id): JsonResponse
    {
        if (!$this->can('canApproveDeliverables')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $deliverable = Deliverable::whereHas('project', fn($p) => $p->whereIn('id', $this->visibleProjectIds()))
            ->findOrFail($id);

        if ($deliverable->project?->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        DB::transaction(function () use ($deliverable) {
            $deliverable->update(['status' => 'approved', 'approved_at' => now()]);

            if ($deliverable->task_id) {
                $task = Task::find($deliverable->task_id);
                if ($task) {
                    $task->update([
                        'progress'     => $this->progressForStatus('approved'),
                        'approved_at'  => now(),
                        'delivered_at' => now(),
                    ]);
                }
            }
        });

        $this->logActivity($deliverable->project->company_id, 'deliverable_approved', $deliverable->task_id ? 'Task' : 'Project', $deliverable->task_id ?? $deliverable->project_id, ['deliverable_id' => $deliverable->id]);
        $this->logTaskRevision($deliverable->task_id, "{$this->userName()} approved this task's deliverable");

        $taskTitle = $deliverable->task?->title;
        $taskNumber = $deliverable->task?->task_number;
        $this->notifyDeliverableEvent(
            $deliverable, $deliverable->uploaded_by, $this->user()->id,
            'deliverable_approved', 'Deliverable approved',
            $taskTitle
                ? "Your deliverable for task {$taskNumber} - \"{$taskTitle}\" has been approved."
                : "Your deliverable in project \"{$deliverable->project->name}\" has been approved."
        );

        return ApiResponse::success($deliverable->fresh(), 'Deliverable approved');
    }

    // PM rejects a submitted deliverable — a harsher outcome than Request
    // Revision; the linked task is left actionable (not completed).
    public function reject(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canApproveDeliverables')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $deliverable = Deliverable::whereHas('project', fn($p) => $p->whereIn('id', $this->visibleProjectIds()))
            ->findOrFail($id);

        if ($deliverable->project?->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        // QA Failed / Revision Required requires a reason when it drives a
        // linked task's status — a project-level deliverable (no task_id)
        // keeps the feedback optional as before.
        $validated = $request->validate([
            'feedback' => [$deliverable->task_id ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($deliverable, $validated) {
            $deliverable->update(['status' => 'rejected']);

            if ($deliverable->task_id) {
                Task::where('id', $deliverable->task_id)->update(['progress' => $this->progressForStatus('rejected')]);
            }

            if (!empty($validated['feedback'])) {
                Revision::create([
                    'deliverable_id' => $deliverable->id,
                    'requested_by'   => $this->user()->id,
                    'feedback'       => $validated['feedback'],
                    'status'         => 'open',
                ]);
            }
        });

        $this->logActivity($deliverable->project->company_id, 'deliverable_rejected', $deliverable->task_id ? 'Task' : 'Project', $deliverable->task_id ?? $deliverable->project_id, ['deliverable_id' => $deliverable->id]);
        $this->logTaskRevision($deliverable->task_id, "{$this->userName()} rejected this task's deliverable" . (!empty($validated['feedback']) ? ": " . \Illuminate\Support\Str::limit($validated['feedback'], 80) : ''));

        $taskTitle = $deliverable->task?->title;
        $taskNumber = $deliverable->task?->task_number;
        $this->notifyDeliverableEvent(
            $deliverable, $deliverable->uploaded_by, $this->user()->id,
            'deliverable_rejected', 'Deliverable rejected',
            $taskTitle
                ? "Your deliverable for task {$taskNumber} - \"{$taskTitle}\" was rejected."
                : "Your deliverable in project \"{$deliverable->project->name}\" was rejected."
        );

        return ApiResponse::success($deliverable->fresh(), 'Deliverable rejected');
    }

    // GET /user/deliverables/{id}/revisions
    public function revisions(int $deliverableId): JsonResponse
    {
        if (!$this->can('canViewDeliverables')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $deliverable = Deliverable::whereHas('project', fn($p) => $p->whereIn('id', $this->visibleProjectIds()))
            ->findOrFail($deliverableId);

        return ApiResponse::success($deliverable->revisions()->with('requestedBy:id,name')->orderByDesc('id')->get());
    }

}
