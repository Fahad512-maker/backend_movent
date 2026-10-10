<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Company Admin has no rows in the `notifications` table for most events
// (Admin isn't a `users` row) — so this feed is a MERGE of two sources:
// (1) the original SystemAuditLog-based synthetic feed (unchanged, kept for
// backward compatibility — every entry tagged source='audit', read state is
// still the single notifications_last_read_at timestamp, no per-row action),
// and (2) real `notifications` rows written via NotificationService with
// recipient_admin_id = this admin (source='notification' — new specific
// events like "task blocked"/"deliverable submitted"/"invoice paid" that now
// support true per-row mark-read/clear, same as the staff side already has).
//
// Scoped to the ACTIVE company (ScopesToActiveCompany), not every company
// this admin owns — a multi-company admin switching to Company A must see
// (and get an unread count for) only Company A's notifications, never a
// count mixed in from Company B. "All Companies" mode still merges every
// owned company, same as every other admin list page.
class NotificationController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    public function index(): JsonResponse
    {
        $admin = $this->admin();
        $companyIds = $this->activeCompanyIds();

        // Every Admin-side write site records SystemAuditLog.user_id = null
        // (Company Admin isn't a `users` row) — so a null user_id here IS this
        // admin's own action. Excluding it is what stops "Admin creates a
        // project" from showing up as a notification to that same Admin.
        $logs = SystemAuditLog::whereIn('company_id', $companyIds)
            ->whereNotNull('user_id')
            ->with(['user:id,name', 'company:id,name'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $lastRead = $admin->notifications_last_read_at;
        $readAuditIds = $admin->read_audit_log_ids ?? [];

        // Brand rows written before the name was recorded in new_values carry
        // nothing but an id, so the live name is looked up here — one query for
        // the whole page — and used as the fallback when describing them.
        $brandIds = $logs->where('entity_type', 'Brand')->pluck('entity_id')->filter()->unique()->all();
        $brandNames = $brandIds
            ? Brand::withTrashed()->whereIn('id', $brandIds)->pluck('name', 'id')->all()
            : [];

        // Same story for brand.assigned rows written before assignee names were
        // recorded — they carry only ids, so those are resolved here rather
        // than falling back to "3 team members".
        $assigneeIds = $logs->where('action', 'brand.assigned')
            ->flatMap(fn ($l) => empty($l->new_values['users']) ? (array) ($l->new_values['user_ids'] ?? []) : [])
            ->unique()->values()->all();
        $assigneeNames = $assigneeIds
            ? User::whereIn('id', $assigneeIds)->pluck('name', 'id')->all()
            : [];

        $auditItems = $logs->map(function ($log) use ($lastRead, $readAuditIds, $brandNames, $assigneeNames) {
            $values = $log->new_values ?? [];

            if ($log->action === 'brand.assigned' && empty($values['users'])) {
                $ids = (array) ($values['user_ids'] ?? []);
                $resolved = array_values(array_filter(array_map(fn ($id) => $assigneeNames[$id] ?? null, $ids)));
                // All-or-nothing: a partial list (someone since deleted) would
                // under-report who holds the brand, so the count wording wins.
                if ($resolved && count($resolved) === count($ids)) {
                    $values['users'] = $resolved;
                }
            }

            $person = $values['sender'] ?? $values['author'] ?? null;
            $preview = $values['preview'] ?? null;
            $actorName = $log->user?->name;
            $entityName = $log->entity_type === 'Brand'
                ? ($brandNames[$log->entity_id] ?? null)
                : null;

            // Dotted actions ("brand.transferred") read as one word without
            // this, so '.' is split the same way '_' already is.
            $title = Str::of($log->action)->replace(['_', '.'], ' ')->title()->toString();
            if (!empty($values['project'])) {
                $title .= " · {$values['project']}";
            }
            if (!empty($values['task'])) {
                $title .= " · {$values['task']}";
            }

            $body = $preview && $person
                ? "{$person}: {$preview}"
                : ($this->describeAction($log->action, $values, $actorName, $entityName)
                    ?? ($actorName
                        ? "{$actorName} — {$log->entity_type} #{$log->entity_id}"
                        : ($log->entity_type ? "{$log->entity_type} #{$log->entity_id}" : null)));

            $isRead = ($lastRead !== null && $log->created_at <= $lastRead) || in_array($log->id, $readAuditIds, true);

            return [
                'id'         => $log->id,
                'key'        => "audit-{$log->id}",
                'source'     => 'audit',
                'title'      => $title,
                'body'       => $body,
                'module_key' => $log->module_key,
                'company_id'   => $log->company_id,
                'company_name' => $log->company?->name,
                'company'      => $log->company ? [
                    'id'   => $log->company->id,
                    'name' => $log->company->name,
                ] : null,
                'is_read'    => $isRead,
                'created_at' => $log->created_at,
                'link'       => $this->resolveLink($log->entity_type, $log->entity_id, $log->action),
            ];
        });

        $realRows = Notification::where('recipient_admin_id', $admin->id)
            ->whereIn('company_id', $companyIds)
            ->whereNull('cleared_at')
            ->with('company:id,name')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $realItems = $realRows->map(fn ($n) => [
            'id'         => $n->id,
            'key'        => "notification-{$n->id}",
            'source'     => 'notification',
            'title'      => $n->title,
            'body'       => $n->body,
            'module_key' => $n->module,
            'company_id'   => $n->company_id,
            'company_name' => $n->company?->name,
            'company'      => $n->company ? [
                'id'   => $n->company->id,
                'name' => $n->company->name,
            ] : null,
            'is_read'    => $n->is_read,
            'created_at' => $n->created_at,
            'link'       => $n->url ?? ($n->data['link'] ?? null),
        ]);

        $combined = $auditItems->merge($realItems)
            ->sortByDesc('created_at')
            ->values()
            ->take(30);

        $unreadCount = $auditItems->where('is_read', false)->count()
            + $realRows->where('is_read', false)->count();

        return ApiResponse::success([
            'notifications' => $combined,
            'unread_count'  => $unreadCount,
        ]);
    }

    // PATCH /admin/notifications/{id}/read — `{id}` is ambiguous on its own
    // (a real notifications row and a SystemAuditLog row can share the same
    // numeric id), so this tries the real-row table first and only falls
    // back to recording the id as "read" against the audit feed if no real
    // row matches — that's how a legacy audit-log-backed entry (source=
    // 'audit') now gets genuine per-item read state despite SystemAuditLog
    // itself having no is_read column.
    public function markRead(int $id): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();

        $notification = Notification::where('recipient_admin_id', $this->admin()->id)
            ->whereIn('company_id', $companyIds)
            ->find($id);

        if ($notification) {
            $notification->update(['is_read' => true, 'read_at' => now()]);

            return ApiResponse::success(null, 'Marked as read');
        }

        $log = SystemAuditLog::whereIn('company_id', $companyIds)->findOrFail($id);

        $admin = $this->admin();
        $readIds = $admin->read_audit_log_ids ?? [];
        if (!in_array($log->id, $readIds, true)) {
            $readIds[] = $log->id;
            // Cap growth — only the latest 30 audit logs are ever shown in
            // the feed, so anything older than that never needs to be
            // looked up again; keep a bit of headroom past that.
            sort($readIds);
            $readIds = array_slice($readIds, -200);
            $admin->update(['read_audit_log_ids' => $readIds]);
        }

        return ApiResponse::success(null, 'Marked as read');
    }

    // DELETE /admin/notifications/{id} — soft-clear, own notifications only.
    public function clear(int $id): JsonResponse
    {
        $notification = Notification::where('recipient_admin_id', $this->admin()->id)
            ->whereIn('company_id', $this->activeCompanyIds())
            ->findOrFail($id);

        $notification->update(['cleared_at' => now(), 'is_read' => true, 'read_at' => $notification->read_at ?? now()]);

        return ApiResponse::success(null, 'Notification cleared');
    }

    // DELETE /admin/notifications — clear all of THIS admin's own real
    // notifications; never touches another admin's or a staff member's rows.
    public function clearAll(): JsonResponse
    {
        Notification::where('recipient_admin_id', $this->admin()->id)
            ->whereIn('company_id', $this->activeCompanyIds())
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now(), 'is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications cleared');
    }

    // Human-readable body for action types that don't carry the chat/comment
    // sender+preview shape — SystemAuditLog.new_values varies by write site
    // (raw field diffs, not pre-formatted text), so this fills in "who did
    // what" for the common Project/Task/Deliverable actions rather than
    // falling all the way back to a bare "Task #38". Returns null for any
    // action not covered here, so the caller's own fallback still applies.
    private function describeAction(string $action, array $values, ?string $actorName, ?string $entityName = null): ?string
    {
        // Brand events are named after the brand, never "Brand #5" — which of
        // the company's trading names was created, handed to someone or moved
        // is the whole point of the notification. Handled before the actor
        // guard below so a deleted actor still leaves a readable line.
        if (str_starts_with($action, 'brand.')) {
            return $this->describeBrandAction($action, $values, $actorName, $entityName);
        }

        if (!$actorName) return null;

        return match ($action) {
            'task_created'          => "{$actorName} created this task.",
            'task_assigned'         => "{$actorName} assigned this task.",
            'task_status_updated'   => isset($values['status'])
                ? "{$actorName} changed the status to \"" . str_replace('_', ' ', $values['status']) . "\"."
                : "{$actorName} updated the task status.",
            'team_assigned'         => "{$actorName} updated the project team.",
            'deliverable_uploaded'  => "{$actorName} submitted a deliverable.",
            'deliverable_approved'  => "{$actorName} approved a deliverable.",
            'deliverable_rejected'  => "{$actorName} rejected a deliverable.",
            'revision_requested'    => "{$actorName} requested a revision.",
            'revision_resolved'     => "{$actorName} resolved a revision.",
            'task_started'              => "{$actorName} started working on this task.",
            'task_submitted_for_review' => "{$actorName} submitted this task for review.",
            'invoice_payment_confirmed' => "Payment was confirmed for this invoice.",
            'invoice_payment_rejected'  => "A payment attempt for this invoice failed or was rejected.",
            'created'               => "{$actorName} created this.",
            'updated'               => "{$actorName} made changes.",
            default => null,
        };
    }

    // The brand half of describeAction(). The name comes from the audit
    // payload's `brand` key (every brand.* write site records it), falling back
    // to `name` for the old brand.created shape and then to $entityName — the
    // brand looked up live by id — so entries written before either key existed
    // still say which brand they mean.
    private function describeBrandAction(string $action, array $values, ?string $actorName, ?string $entityName): ?string
    {
        $name = $values['brand'] ?? $values['name'] ?? $entityName;
        // The article lives inside the label so a brand whose name can no
        // longer be resolved (hard-deleted) still reads as a sentence.
        $brand = $name ? "the brand \"{$name}\"" : 'a brand';
        $who   = $actorName ?: 'Someone';

        if ($action === 'brand.assigned' || $action === 'brand.unassigned') {
            $ids   = (array) ($values['user_ids'] ?? []);
            $names = array_values(array_filter((array) ($values['users'] ?? [])));

            // assign() replaces the whole set, so an empty list is "nobody
            // works this brand any more" rather than a no-op.
            if (count($ids) === 0) {
                return "{$who} removed everyone from {$brand}.";
            }

            $to = $names
                ? implode(', ', $names)
                : count($ids) . ' team member' . (count($ids) > 1 ? 's' : '');

            return "{$who} assigned {$brand} to {$to}.";
        }

        if ($action === 'brand.transferred') {
            $from = $values['from'] ?? null;
            $to   = $values['to']   ?? null;

            return $from && $to
                ? "{$who} transferred {$brand} from {$from} to {$to}."
                : ($to
                    ? "{$who} transferred {$brand} to {$to}."
                    : "{$who} transferred {$brand} to another company.");
        }

        return match ($action) {
            'brand.created'     => "{$who} created {$brand}.",
            'brand.updated'     => "{$who} updated {$brand}.",
            'brand.deleted'     => "{$who} deleted {$brand}.",
            'brand.activated'   => "{$who} activated {$brand}.",
            'brand.deactivated' => "{$who} deactivated {$brand}.",
            default             => $name ? "{$who} updated {$brand}." : null,
        };
    }

    // Where clicking this notification should take the admin — SystemAuditLog
    // rows come from many different write sites across the app with varying
    // entity_type values, so this only covers the ones with a real admin-side
    // detail page; anything else (or a missing entity_id) is null, and the
    // frontend just won't navigate for those rather than link somewhere wrong.
    private function resolveLink(?string $entityType, ?int $entityId, ?string $action = null): ?string
    {
        if (!$entityType || !$entityId) return null;

        // A deleted entity has no page left to open — the notification still
        // says what happened, it just doesn't navigate into a 404.
        if ($action && str_ends_with($action, '.deleted')) return null;

        return match ($entityType) {
            'Brand'       => "/admin/brands/{$entityId}",
            'Invoice'     => "/admin/invoices/{$entityId}",
            'Lead'        => "/admin/leads/{$entityId}",
            'Client'      => "/admin/clients/{$entityId}",
            'Project'     => "/admin/projects/{$entityId}",
            'Employee'    => "/admin/employees/{$entityId}",
            'Recruitment' => "/admin/recruitment/{$entityId}",
            'Company'     => "/admin/companies/{$entityId}",
            'User'        => "/users/{$entityId}/edit",
            // Tasks have no standalone detail page — send to the tasks list
            // rather than a dead link.
            'Task'        => '/admin/tasks',
            default       => null,
        };
    }

    // Powers the Sidebar's per-nav-item red dots (Tasks/Projects). Each
    // category tracks its own last-read timestamp (tasks_last_read_at /
    // projects_last_read_at) rather than sharing notifications_last_read_at,
    // so visiting the Tasks list only clears the Tasks dot, not Projects —
    // entity_type is a reliable discriminator since every Task/Project audit
    // log site sets it consistently (see Api\User\TaskController::logActivity
    // and Api\Admin\ProjectController's SystemAuditLog::create calls).
    public function unreadCounts(): JsonResponse
    {
        $admin = $this->admin();
        // Same self-exclusion as index() — don't count the admin's own actions.
        $base = SystemAuditLog::whereIn('company_id', $this->activeCompanyIds())->whereNotNull('user_id');

        $tasksSince = $admin->tasks_last_read_at ?? $admin->notifications_last_read_at;
        $projectsSince = $admin->projects_last_read_at ?? $admin->notifications_last_read_at;

        $tasksQuery = (clone $base)->where('entity_type', 'Task');
        if ($tasksSince !== null) {
            $tasksQuery->where('created_at', '>', $tasksSince);
        }

        $projectsQuery = (clone $base)->where('entity_type', 'Project');
        if ($projectsSince !== null) {
            $projectsQuery->where('created_at', '>', $projectsSince);
        }

        return ApiResponse::success(['tasks' => $tasksQuery->count(), 'projects' => $projectsQuery->count()]);
    }

    // Called when the admin visits the Tasks or Projects list page, so that
    // page's dot clears without affecting the other category or the general
    // bell dropdown's own read state.
    public function markCategoryRead(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => 'required|in:tasks,projects']);
        $column = $data['category'] === 'tasks' ? 'tasks_last_read_at' : 'projects_last_read_at';

        $this->admin()->update([$column => now()]);

        return ApiResponse::success(null, 'Marked as read');
    }

    public function markAllRead(): JsonResponse
    {
        $admin = $this->admin();
        $companyIds = $this->activeCompanyIds();

        // Per-item ids, not a `notifications_last_read_at` timestamp bump — a
        // single cutoff can't tell companies apart, so bumping it while
        // looking at Company A would silently mark Company B's not-yet-seen
        // audit items read too the moment the admin switches there. Recording
        // exactly the ids currently in view (the same 30-row window index()
        // shows, already scoped to the active company) keeps this action
        // isolated to whichever company (or companies, in "All Companies"
        // mode) is active right now.
        $visibleLogIds = SystemAuditLog::whereIn('company_id', $companyIds)
            ->whereNotNull('user_id')
            ->orderByDesc('created_at')
            ->limit(30)
            ->pluck('id')
            ->all();

        if ($visibleLogIds) {
            $readIds = array_values(array_unique(array_merge($admin->read_audit_log_ids ?? [], $visibleLogIds)));
            sort($readIds);
            // Only the latest 30 audit logs are ever shown in the feed, so
            // anything older than that never needs to be looked up again;
            // keep a bit of headroom past that.
            $readIds = array_slice($readIds, -200);
            $admin->update(['read_audit_log_ids' => $readIds]);
        }

        // Tasks/Projects sidebar dots are a separate feature (not scoped
        // per-company yet) — unaffected by the change above.
        $admin->update([
            'tasks_last_read_at'    => now(),
            'projects_last_read_at' => now(),
        ]);

        // Also sweep the real per-row notifications targeting this admin,
        // scoped to the same active company (or companies).
        Notification::where('recipient_admin_id', $admin->id)
            ->whereIn('company_id', $companyIds)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read');
    }
}
