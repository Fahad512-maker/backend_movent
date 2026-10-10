<?php

namespace App\Services;

use App\Models\ProjectTeamMember;
use App\Models\Task;

// Single source of truth for who may change a Task's status, shared by both
// Api\Admin\TaskController::update() and Api\User\TaskController::update()
// so the two guards can't drift apart. Jira-style free jump: an allowed
// actor may set the task to ANY other legal status, no forced order, no
// per-hop-specific permission, no required comment. 'review' and
// 'cancelled' stay legal legacy/side statuses (see Task::STATUS_LABELS),
// reachable the same way as everything else.
class TaskStatusService
{
    /**
     * @param array $actor [
     *   'type' => 'user'|'admin', 'id' => int, 'name' => string,
     *   'is_pm' => bool, 'is_assignee' => bool, 'role_type' => ?string,
     *   'perms' => string[] (granted project_management permission keys — User guard only, ignored for Admin),
     * ]
     */
    public static function canChangeTaskStatus(Task $task, array $actor): bool
    {
        return match (true) {
            ($actor['type'] ?? null) === 'admin'                           => true,
            $actor['is_pm'] ?? false                                       => true,
            ($actor['role_type'] ?? null) === 'qa'                         => true,
            in_array('canOverrideTaskStatus', $actor['perms'] ?? [], true) => true,
            $actor['is_assignee'] ?? false                                 => true,
            default                                                       => false,
        };
    }

    // Stamps the status-changing actor, the one remaining conditional
    // timestamp/handoff (ready_for_production), writes the task_activities
    // entry, and fires the transition's notifications. Callers still do
    // their own $task->update() for non-status fields (title/assignee/etc.)
    // — this only owns the status-transition side effects.
    public static function applyTransition(Task $task, string $to, ?string $comment, array $actor, ?int $productionAssignedTo = null): void
    {
        $from = $task->status;
        $now = now();

        $update = ['status' => $to];
        if ($actor['type'] === 'user') {
            $update['status_changed_by_user_id']  = $actor['id'];
            $update['status_changed_by_admin_id'] = null;
        } else {
            $update['status_changed_by_admin_id'] = $actor['id'];
            $update['status_changed_by_user_id']  = null;
        }

        // Optional — unlike a required handoff, a null value here is a
        // legitimate "not handed to anyone specific" choice, not a
        // validation failure.
        if ($to === 'ready_for_production') { $update['ready_for_production_at'] = $now; $update['production_assigned_to'] = $productionAssignedTo; }
        if ($to === 'completed')            $update['completed_at'] = $task->completed_at ?? $now;

        $task->update($update);

        $actorName = $actor['name'] ?? ($actor['type'] === 'admin' ? 'Admin' : 'User');
        $logType = $to === 'completed' ? 'completed' : 'status_changed';

        $description = match ($to) {
            'blocked'              => "{$actorName} marked task {$task->task_number} as Blocked" . ($comment ? ": {$comment}" : '.'),
            'ready_for_production' => "{$actorName} marked task {$task->task_number} as Ready for Production." . ($comment ? " {$comment}" : ''),
            'completed'            => "{$actorName} marked task {$task->task_number} as Done / Completed.",
            default                => "{$actorName} moved task {$task->task_number} from " . self::label($from) . ' to ' . self::label($to) . '.',
        };

        $task->logActivity($logType, $description, $actorName, array_filter([
            'old_status' => $from, 'new_status' => $to, 'comment' => $comment,
        ], fn ($v) => $v !== null));

        self::notify($task, $to, $comment, $actor);
    }

    private static function label(string $status): string
    {
        return Task::STATUS_LABELS[$status] ?? str_replace('_', ' ', $status);
    }

    private static function notify(Task $task, string $to, ?string $comment, array $actor): void
    {
        $project = $task->project;
        if (!$project) return;

        $common = [
            'company_id'  => $project->company_id,
            'module'      => 'project_management',
            'entity_type' => 'Task',
            'entity_id'   => $task->id,
            'url'         => "/projects/{$project->id}/tasks/{$task->id}",
        ];
        $actorKey = $actor['type'] === 'admin' ? ['actor_admin_id' => $actor['id']] : ['actor_user_id' => $actor['id']];
        $actorAdminIdForCoAdmins = $actor['type'] === 'admin' ? $actor['id'] : null;

        // The project manager gets notified on every status change,
        // whatever the new status is — pointed at the project detail page
        // (not the task sub-page) so they land on the full task list to
        // review, rather than a single task. sendToMany() already self-skips
        // when the actor IS the PM, so a PM changing their own task's
        // status doesn't notify themselves.
        if ($project->project_manager_id) {
            NotificationService::sendToMany([['user_id' => $project->project_manager_id]], array_merge($common, $actorKey, [
                'url'     => "/projects/{$project->id}",
                'type'    => 'task_status_changed',
                'title'   => 'Task status updated',
                'message' => "Task '{$task->task_number} - {$task->title}' status changed to " . self::label($to) . '.',
            ]));
        }

        if ($to === 'ready_for_production') {
            $recipients = [];
            // A specific Production/Deployment user handoff (optional) takes
            // priority; otherwise fall back to broadcasting to every
            // production_user-role team member on the project, as before.
            if ($task->production_assigned_to) {
                $recipients[] = ['user_id' => $task->production_assigned_to];
            } else {
                foreach (ProjectTeamMember::where('project_id', $project->id)->where('role_in_project', 'production_user')->pluck('user_id') as $id) {
                    $recipients[] = ['user_id' => $id];
                }
            }

            NotificationService::sendToMany($recipients, array_merge($common, $actorKey, [
                'type'    => 'task_ready_for_production',
                'title'   => 'Task ready for production',
                'message' => "Task '{$task->task_number} - {$task->title}' is ready for production.",
            ]));

            NotificationService::notifyCompanyAdmins($project->company_id, $actorAdminIdForCoAdmins, array_merge($common, [
                'type'    => 'task_ready_for_production',
                'title'   => 'Task ready for production',
                'message' => "Task '{$task->task_number} - {$task->title}' is ready for production.",
            ]));
        }

        if ($to === 'completed') {
            $recipients = [];
            if ($task->assigned_to) $recipients[] = ['user_id' => $task->assigned_to];

            NotificationService::sendToMany($recipients, array_merge($common, $actorKey, [
                'type'    => 'task_completed',
                'title'   => 'Task completed',
                'message' => "Task '{$task->task_number} - {$task->title}' was marked Done / Completed.",
            ]));

            NotificationService::notifyCompanyAdmins($project->company_id, $actorAdminIdForCoAdmins, array_merge($common, [
                'type'    => 'task_completed',
                'title'   => 'Task completed',
                'message' => "Task '{$task->task_number} - {$task->title}' was marked Done / Completed.",
            ]));
        }
    }
}
