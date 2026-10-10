<?php

namespace App\Services;

use App\Mail\ProjectActivatedMail;
use App\Models\Client;
use App\Models\CompanyModule;
use App\Models\Deliverable;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Revision;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Shared readiness check for the "Mark as Complete" action, used by both
// Api\Admin\ProjectController and Api\User\ProjectController so the rule set
// can never drift between guards. Pure data check — permission gating (who
// is ALLOWED to invoke it) stays in each controller's own can()/permission
// logic, mirroring how the rest of this codebase splits "what's true" from
// "who's allowed".
class ProjectCompletionService
{
    // Task statuses that mean "no longer outstanding work" — cancelled tasks
    // are abandoned, not required, so they don't block completion.
    private const TASK_DONE_STATUSES = ['completed', 'cancelled'];

    public function checkReadiness(Project $project): array
    {
        $pendingTasks = Task::where('project_id', $project->id)
            ->whereNotIn('status', self::TASK_DONE_STATUSES)
            ->get(['id', 'title', 'status', 'due_date', 'is_production_task']);

        $overdueTasks = $pendingTasks->filter(function (Task $task) {
            return $task->due_date && Carbon::parse($task->due_date)->isPast();
        })->values();

        // Re-submitting a task-tied deliverable (e.g. after a revision
        // request) always INSERTs a new row at the next version rather than
        // updating the old one — nothing in this app ever transitions a
        // superseded row out of whatever status it was left at. Left
        // unfiltered, an old 'revision_requested'/'submitted' row would
        // block "Mark as Complete" FOREVER, even once a newer version was
        // uploaded and approved, with no button anywhere to clear it. Only
        // each task's LATEST version can still be genuinely outstanding — a
        // project-level deliverable (task_id null) has no version cycle at
        // all (every upload is independent, not a revision of another), so
        // every one of those stays in play on its own.
        $currentDeliverables = Deliverable::where('project_id', $project->id)
            ->get(['id', 'task_id', 'title', 'status', 'version'])
            ->groupBy(fn (Deliverable $d) => $d->task_id ?? "solo-{$d->id}")
            ->map(fn ($group) => $group->sortByDesc('version')->first())
            ->values();

        // 'submitted' (current convention, Api\User\ProductionController::
        // uploadDeliverable()) and 'delivered' (Api\Admin\ProductionController::
        // storeDeliverable()'s older convention for a fresh, not-yet-reviewed
        // upload) both mean "awaiting review" depending on which panel
        // uploaded it — block completion on either so a fresh upload from
        // neither side can slip through unreviewed.
        $pendingDeliverables = $currentDeliverables->whereIn('status', ['delivered', 'submitted'])->values();

        $revisionRequestedDeliverables = $currentDeliverables->where('status', 'revision_requested')->values();

        $pendingRevisions = Revision::whereIn('deliverable_id', $currentDeliverables->pluck('id'))
            ->whereIn('status', ['open', 'in_progress'])
            ->with('deliverable:id,title')
            ->get(['id', 'deliverable_id', 'status', 'feedback']);

        $blockers = [
            'pending_tasks' => $pendingTasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'status' => $t->status,
                'overdue' => (bool) ($t->due_date && Carbon::parse($t->due_date)->isPast()),
            ])->values(),
            'pending_deliverables' => $pendingDeliverables->map(fn (Deliverable $d) => [
                'id' => $d->id, 'title' => $d->title, 'status' => $d->status,
            ])->values(),
            'pending_revisions' => $pendingRevisions->map(fn (Revision $r) => [
                'id' => $r->id, 'deliverable_id' => $r->deliverable_id,
                'deliverable_title' => $r->deliverable?->title, 'status' => $r->status,
            ])->values()->merge($revisionRequestedDeliverables->map(fn (Deliverable $d) => [
                'id' => null, 'deliverable_id' => $d->id,
                'deliverable_title' => $d->title, 'status' => $d->status,
            ])->values()),
            'overdue_tasks' => $overdueTasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'due_date' => $t->due_date,
            ])->values(),
        ];

        $ready = $blockers['pending_tasks']->isEmpty()
            && $blockers['pending_deliverables']->isEmpty()
            && $blockers['pending_revisions']->isEmpty();

        return ['ready' => $ready, 'blockers' => $blockers];
    }

    public function hasUnpaidInvoice(Project $project): bool
    {
        $unpaidStatuses = ['draft', 'sent', 'partially_paid', 'overdue'];

        if ($project->invoice && in_array($project->invoice->status, $unpaidStatuses)) {
            return true;
        }

        // A strict superset of the single-invoice check above — a project
        // with only its original invoice behaves identically; one with
        // additional linked invoices (deposit/milestone/final/change
        // request, see Project::invoices()) now correctly blocks completion
        // on any of them too.
        return $project->invoices()->whereIn('status', $unpaidStatuses)->exists();
    }

    // Fan-out target list for lifecycle notifications (complete/close/reopen):
    // PM, every team member, every user assigned a production task on this
    // project, and the linked Seller (if this was a sales handoff) — shared
    // by both guards' controllers so the target set can't drift between them.
    public function notificationTargetUserIds(Project $project): array
    {
        $ids = collect();

        if ($project->project_manager_id) {
            $ids->push($project->project_manager_id);
        }

        $ids = $ids->merge($project->teamMembers()->pluck('user_id'));

        $ids = $ids->merge(
            Task::where('project_id', $project->id)
                ->where('is_production_task', true)
                ->whereNotNull('assigned_to')
                ->pluck('assigned_to')
        );

        if ($project->seller_id) {
            $ids->push($project->seller_id);
        }

        $ids = $ids->unique()->filter()->values();

        // Every caller feeds this straight into Notification::create(),
        // whose user_id FK is NOT NULL and has no ON DELETE fallback here —
        // a stale reference (project_team_members/tasks.assigned_to/etc.
        // pointing at a user hard-deleted through some path that skipped
        // cascading cleanup) would otherwise 500 the whole lifecycle action
        // (complete/close/reopen/etc.) instead of just being one fewer
        // notification sent.
        return User::whereIn('id', $ids)->pluck('id')->values()->all();
    }

    // The only "notify the client" mechanism this codebase has is the
    // visibility='client' slice of project_comments (no push/email channel
    // exists for Client Portal users) — gate posting one on the module
    // actually being active for this project's company.
    public function clientPortalActive(Project $project): bool
    {
        if (!$project->client_id) return false;

        return CompanyModule::where('company_id', $project->company_id)
            ->whereIn('module_key', ['client_portal', 'clients'])
            ->where('is_enabled', true)
            ->exists();
    }

    // Called once, right after activate() — the one place this project's
    // client actually needs to hear about it. Exactly one of two things
    // happens, never both:
    //   - A Client Portal login exists (portal_access + a linked users row)
    //     AND the company still actually has the Client Portal module active
    //     (clientPortalActive() — a client's row can keep stale portal_access
    //     after the company later disables the module) → an in-app
    //     Notification, deep-linking straight to the project.
    //   - Otherwise → an email instead, to whichever address is actually
    //     reachable: the Client's own email if one exists without a usable
    //     portal, or the original Lead's email if this project hasn't been
    //     converted to a Client at all yet. A project with neither (an
    //     internal-only project with no counterparty) is silently a no-op.
    // A failed send here must never fail the activation it rode in on.
    public function notifyClientOfActivation(Project $project, string $companyName): void
    {
        $client = $project->client_id ? Client::find($project->client_id) : null;

        if ($client && $client->portal_access && $client->user_id && $this->clientPortalActive($project)) {
            Notification::create([
                'user_id'    => $client->user_id,
                'company_id' => $project->company_id,
                'type'       => 'project_activated',
                'title'      => 'Project activated',
                'body'       => "\"{$project->name}\" is now active.",
                'data'       => ['project_id' => $project->id, 'link' => "/client/projects/{$project->id}"],
            ]);

            return;
        }

        $recipientEmail = $client?->email ?? $project->lead?->email;
        $recipientName  = $client?->name ?? $project->lead?->name ?? 'there';

        if (!$recipientEmail) {
            return;
        }

        try {
            Mail::to($recipientEmail)->send(new ProjectActivatedMail($project, $companyName, $recipientName));
        } catch (\Throwable $e) {
            Log::warning('Failed to send ProjectActivatedMail: ' . $e->getMessage());
        }
    }
}
