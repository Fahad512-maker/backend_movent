<?php

namespace App\Services;

use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything that hangs off a Client: counted up front for the delete
 * confirmation modal, then permanently removed by the delete itself.
 *
 * Shared by Api\Admin\ClientController and Api\User\ClientController so both
 * sides quote the same numbers and destroy exactly the same rows — the counts
 * an admin approves in the modal are the counts purge() actually takes.
 *
 * This is a hard delete, not the soft delete clients used to get. Almost all
 * of the subtree comes off DB-level cascades: removing a `projects` row takes
 * its tasks, timesheets, deliverables, comments, attachments, team members,
 * activities and compliance_* rows with it, and removing an `invoices` row
 * takes its items, payments, reminders and gateway selections. Only the
 * polymorphic links (chat_threads, notifications — no FK to cascade through)
 * have to be swept by hand.
 */
class ClientDeletionService
{
    // projects.status buckets, as the modal groups them.
    private const PROJECT_ONGOING = ['planning', 'active', 'on_hold'];

    // invoices.status buckets. 'overdue' is reported on its own rather than
    // folded into unpaid, since that's the one an admin needs to notice.
    private const INVOICE_UNPAID = ['draft', 'sent', 'partially_paid'];

    /**
     * The blast radius of deleting this client, for the confirmation modal.
     *
     * Soft-deleted projects are counted too — purge() force-deletes those as
     * well, so leaving them out would under-report what actually goes.
     */
    public static function summary(Client $client): array
    {
        $projectIds = self::projectIds($client);
        $invoiceIds = self::invoiceIds($client);

        $projectsByStatus = Project::withTrashed()
            ->where('client_id', $client->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $invoicesByStatus = Invoice::where('client_id', $client->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $portalUser = $client->user_id ? User::find($client->user_id) : null;

        return [
            'client' => [
                'id'               => $client->id,
                'name'             => $client->name,
                'email'            => $client->email,
                'phone'            => $client->phone,
                'company_name'     => $client->company_name,
                'status'           => $client->status,
                'has_portal_login' => (bool) $portalUser,
                'portal_email'     => $portalUser?->email,
            ],
            'projects' => [
                'total'     => (int) $projectsByStatus->sum(),
                'ongoing'   => (int) self::sumOf($projectsByStatus, self::PROJECT_ONGOING),
                'completed' => (int) ($projectsByStatus['completed'] ?? 0),
                'cancelled' => (int) ($projectsByStatus['cancelled'] ?? 0),
            ],
            'invoices' => [
                'total'     => (int) $invoicesByStatus->sum(),
                'paid'      => (int) ($invoicesByStatus['paid'] ?? 0),
                'unpaid'    => (int) self::sumOf($invoicesByStatus, self::INVOICE_UNPAID),
                'overdue'   => (int) ($invoicesByStatus['overdue'] ?? 0),
                'cancelled' => (int) ($invoicesByStatus['cancelled'] ?? 0),
            ],
            'payments' => [
                'total'    => self::paymentsQuery($client)->count(),
                // One row per currency — a client billed in more than one
                // can't be summed into a single figure.
                'received' => self::paymentsReceived($client),
            ],
            'other' => [
                'tasks'              => DB::table('tasks')->whereIn('project_id', $projectIds)->count(),
                'timesheets'         => DB::table('timesheets')
                    ->join('tasks', 'tasks.id', '=', 'timesheets.task_id')
                    ->whereIn('tasks.project_id', $projectIds)->count(),
                'deliverables'       => DB::table('deliverables')->whereIn('project_id', $projectIds)->count(),
                'compliance_cases'   => DB::table('compliance_cases')
                    ->where('client_id', $client->id)
                    ->orWhereIn('project_id', $projectIds)->count(),
                'chat_threads'       => self::chatThreads($client, $projectIds, $invoiceIds)->count(),
                'portal_permissions' => DB::table('client_portal_permissions')->where('client_id', $client->id)->count(),
            ],
        ];
    }

    /**
     * Permanently remove the client and every record above. Irreversible —
     * callers must have taken an explicit confirmation first.
     *
     * @param  int|null  $actorUserId  Sub-user who pressed delete; null when
     *                                 the actor is a Company Admin, which is
     *                                 not a User row (see EmployeeController).
     */
    public static function purge(Client $client, ?int $actorUserId = null): void
    {
        // Snapshot before anything goes — once the rows are gone this audit
        // row is the only remaining record that they ever existed.
        $summary = self::summary($client);

        DB::transaction(function () use ($client, $actorUserId, $summary) {
            $projectIds = self::projectIds($client);
            $invoiceIds = self::invoiceIds($client);

            // Polymorphic links first — linked_to_id/entity_id carry no
            // foreign key, so nothing would clean them up on their own.
            self::chatThreads($client, $projectIds, $invoiceIds)->delete();
            self::notifications($client, $projectIds, $invoiceIds)->delete();
            DB::table('compliance_activity_log')
                ->where('client_id', $client->id)
                ->orWhereIn('project_id', $projectIds)
                ->delete();

            // Parent rows — the DB cascades take each subtree. Projects go
            // before invoices only so the nullOnDelete write-back on
            // invoices.project_id lands on rows that are about to disappear
            // anyway; neither order leaves anything behind.
            Project::withTrashed()->whereIn('id', $projectIds)->forceDelete();
            Invoice::whereIn('id', $invoiceIds)->delete();

            // The portal login is a real User row the auth system shares —
            // deactivate it rather than delete, exactly as disable-portal
            // does, so its chat messages and timesheets keep an author.
            if ($client->user_id) {
                User::where('id', $client->user_id)->update(['is_active' => false]);
            }

            // Takes client_portal_permissions and compliance_cases with it.
            $client->forceDelete();

            SystemAuditLog::create([
                'company_id'  => $client->company_id,
                'user_id'     => $actorUserId,
                'action'      => 'client_permanently_deleted',
                'module_key'  => 'client',
                'entity_type' => 'Client',
                'entity_id'   => $client->id,
                'old_values'  => $summary,
            ]);
        });
    }

    /** @return Collection<int, int> */
    private static function projectIds(Client $client): Collection
    {
        return Project::withTrashed()->where('client_id', $client->id)->pluck('id');
    }

    /** @return Collection<int, int> */
    private static function invoiceIds(Client $client): Collection
    {
        return Invoice::where('client_id', $client->id)->pluck('id');
    }

    /**
     * Chat threads reachable from this client — its own sales/direct thread
     * plus every thread hung off one of its projects or invoices.
     */
    private static function chatThreads(Client $client, Collection $projectIds, Collection $invoiceIds): Builder
    {
        return ChatThread::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('linked_to_type', 'Client')->where('linked_to_id', $client->id))
                ->orWhere(fn ($w) => $w->where('linked_to_type', 'Project')->whereIn('linked_to_id', $projectIds))
                ->orWhere(fn ($w) => $w->where('linked_to_type', 'Invoice')->whereIn('linked_to_id', $invoiceIds)));
    }

    /**
     * Notifications pointing at any of the deleted entities — left behind
     * they'd deep-link users straight into a 404.
     */
    private static function notifications(Client $client, Collection $projectIds, Collection $invoiceIds): Builder
    {
        return Notification::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('entity_type', 'Client')->where('entity_id', $client->id))
                ->orWhere(fn ($w) => $w->where('entity_type', 'Project')->whereIn('entity_id', $projectIds))
                ->orWhere(fn ($w) => $w->where('entity_type', 'Invoice')->whereIn('entity_id', $invoiceIds)));
    }

    private static function paymentsQuery(Client $client): \Illuminate\Database\Query\Builder
    {
        return DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.client_id', $client->id);
    }

    /**
     * Confirmed money actually taken, per currency. Pending/failed/refunded
     * rows are excluded — they were never received.
     *
     * @return array<int, array{currency: string, amount: string}>
     */
    private static function paymentsReceived(Client $client): array
    {
        // payments.currency was added after the table shipped, so older rows
        // fall back to the invoice's own currency (same coalesce the reports
        // endpoints use).
        $currency = 'COALESCE(payments.currency, invoices.currency)';

        return self::paymentsQuery($client)
            ->where('payments.status', 'confirmed')
            ->selectRaw("$currency as currency, SUM(payments.amount) as amount")
            ->groupByRaw($currency)
            ->orderByRaw('SUM(payments.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency ?? '—',
                'amount'   => number_format((float) $row->amount, 2, '.', ''),
            ])
            ->all();
    }

    /**
     * @param  Collection<string, int>  $counts
     * @param  array<int, string>  $statuses
     */
    private static function sumOf(Collection $counts, array $statuses): int
    {
        return (int) collect($statuses)->sum(fn (string $s) => $counts[$s] ?? 0);
    }
}
