<?php

namespace App\Services;

use App\Mail\ProjectManagerAssignedMail;
use App\Mail\TaskAssignedMail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\MailDelivery;
use Illuminate\Support\Facades\Log;

/**
 * The single choke point for the two "you've been assigned something" emails.
 *
 * Both guards (Api\Admin\* and Api\User\*) already create an in-app
 * Notification at every assignment site; these calls sit right next to those
 * so the email and the bell always tell the same story.
 *
 * Delivery goes through App\Support\MailDelivery, which sends inline by
 * default and only uses the queue where one is actually being drained — the
 * app must work on hosting with no shell to run `queue:work`. Either way the
 * try/catch keeps a mail failure from failing the task/project save that
 * triggered it (same rule as ClientPortalWelcomeMail/UserWelcomeMail).
 */
class AssignmentMailService
{
    /**
     * Emails tasks.assigned_to about a task they've just been given.
     *
     * $actorUserId is the staff user performing the assignment (null for a
     * Company Admin, who isn't a `users` row) — assigning a task to yourself
     * never sends you an email about it.
     */
    public static function taskAssigned(Task $task, Project $project, ?string $assignedByName = null, ?int $actorUserId = null): void
    {
        if (!$task->assigned_to) return;
        if ($actorUserId !== null && (int) $task->assigned_to === (int) $actorUserId) return;

        $assignee = User::find($task->assigned_to);
        if (!$assignee || !$assignee->email) return;

        try {
            MailDelivery::to($assignee->email, new TaskAssignedMail(
                $task,
                $project,
                self::companyName($project),
                self::displayName($assignee->name),
                // The guard-agnostic /task/{id} redirector, not a per-guard
                // deep link: it resolves the recipient's own session (Admin vs
                // staff) and the task's project_id, then forwards to the right
                // page — see frontend/app/task/[taskId]/page.tsx. A logged-out
                // recipient lands on /login, then opens this link again.
                self::frontendUrl('/task/' . $task->id),
                $assignedByName,
            ));
        } catch (\Throwable $e) {
            Log::warning('[assignment-mail] task assigned email failed', [
                'task_id' => $task->id, 'user_id' => $assignee->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emails the user who has just become this project's project_manager_id.
     *
     * $managerId is passed explicitly rather than read off the model, because
     * several call sites send this before/without the column being refreshed.
     */
    public static function projectManagerAssigned(Project $project, ?int $managerId, ?string $assignedByName = null, ?int $actorUserId = null): void
    {
        if (!$managerId) return;
        if ($actorUserId !== null && (int) $managerId === (int) $actorUserId) return;

        $manager = User::find($managerId);
        if (!$manager || !$manager->email) return;

        try {
            MailDelivery::to($manager->email, new ProjectManagerAssignedMail(
                $project,
                self::companyName($project),
                self::displayName($manager->name),
                // A Project Manager is always a staff (`users`) row, so the
                // staff-guard route is correct here — same link the in-app
                // 'project_assigned' Notification already uses.
                self::frontendUrl('/projects/' . $project->id),
                $assignedByName,
            ));
        } catch (\Throwable $e) {
            Log::warning('[assignment-mail] project manager email failed', [
                'project_id' => $project->id, 'user_id' => $manager->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    // The tenant's own branded name (business_name when the Company Admin set
    // one), falling back to the app name — matches how every other outbound
    // email in the app titles itself.
    private static function companyName(Project $project): string
    {
        try {
            $name = $project->company?->invoicingProfile()['name'] ?? null;
        } catch (\Throwable) {
            $name = null;
        }

        return trim((string) $name) ?: (string) config('app.name');
    }

    private static function displayName(?string $name): string
    {
        return trim((string) $name) ?: 'there';
    }

    private static function frontendUrl(string $path): string
    {
        return FrontendUrl::to($path);
    }
}
