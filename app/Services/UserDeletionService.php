<?php

namespace App\Services;

use App\Models\CompanyUserAssignment;
use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything a user is holding inside one company: measured before a delete,
 * handed over to someone else on request, and finally destroyed.
 *
 * Deleting a user account is far wider than deleting a client. A client is a
 * branch — its projects and invoices hang off it and nothing else. A person is
 * threaded through the whole company: they manage projects, own leads, are
 * assigned tasks, uploaded files other people rely on, and authored chat
 * messages and audit entries that belong to conversations that outlive them.
 *
 * The `users` foreign keys split into two behaviours, and impact() reports
 * which one each section falls under so the admin sees it before committing:
 *
 *   SET NULL (71 columns) — the record survives and simply loses the person.
 *   Projects, tasks, leads, clients, invoices, support tickets, chat message
 *   authorship, audit log actors.
 *
 *   CASCADE (19 columns) — the record is destroyed with them. Timesheets,
 *   team memberships, chat participation, notifications, report access,
 *   uploaded project-folder files, and the compliance items/policies/reports/
 *   incidents/violations/risk-assessments they created.
 *
 * That second list is why reassignment exists: hand the work over first, then
 * what remains to delete is genuinely only theirs.
 */
class UserDeletionService
{
    /** Names carried per section before it just reports "+N more". */
    private const NAME_LIMIT = 15;

    private const PROJECT_ACTIVE = ['planning', 'active', 'on_hold'];
    private const TASK_OPEN      = ['todo', 'in_progress', 'review'];
    private const LEAD_OPEN      = ['new', 'contacted', 'qualified', 'proposal', 'negotiation'];
    private const TICKET_OPEN    = ['open', 'in_progress'];

    /** Reassignment buckets, in the order the modal offers them. */
    public const REASSIGNABLE = ['projects', 'leads', 'tasks', 'clients', 'support_tickets', 'team'];

    // =========================================================================
    // Impact
    // =========================================================================

    /**
     * What this user holds in $companyId, what a delete would do to each of
     * it, and whether anything must be handed over first.
     */
    public static function impact(User $user, int $companyId): array
    {
        $projectIds = self::companyProjectIds($companyId);

        $sections = array_values(array_filter([
            self::projectsManaged($user, $companyId),
            self::projectsSelling($user, $companyId),
            self::teamAssignments($user, $projectIds),
            self::tasksAssigned($user, $projectIds),
            self::tasksCreated($user, $projectIds),
            self::leadsAssigned($user, $companyId),
            self::clientsManaged($user, $companyId),
            self::supportTickets($user, $companyId),
            self::invoicesCreated($user, $companyId),
            self::complianceRecords($user, $projectIds),
            self::chats($user),
            self::notifications($user),
            self::timesheets($user, $projectIds),
        ]));

        $blockers = array_values(array_map(
            fn (array $s) => [
                'key'          => $s['key'],
                'label'        => $s['label'],
                'count'        => $s['blocking_count'],
                'reassign_key' => $s['reassign_key'],
            ],
            array_filter($sections, fn (array $s) => ($s['blocking_count'] ?? 0) > 0),
        ));

        [$canDelete, $blockReason] = self::hardDeleteGuard($user, $companyId);

        return [
            'user'          => self::identity($user, $companyId),
            'sections'      => $sections,
            'role_extra'    => self::roleExtra($user, $companyId, $projectIds),
            'blockers'      => $blockers,
            'reassignable'  => self::REASSIGNABLE,
            'candidates'    => self::reassignCandidates($user, $companyId),
            'can_delete'    => $canDelete,
            'block_reason'  => $blockReason,
        ];
    }

    private static function identity(User $user, int $companyId): array
    {
        $companies = DB::table('company_user_assignments as cua')
            ->join('companies as c', 'c.id', '=', 'cua.company_id')
            ->where('cua.user_id', $user->id)
            ->get(['c.id', 'c.name', 'cua.status'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status])
            ->all();

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'role_type'         => $user->role_type,
            'custom_role_label' => $user->custom_role_label,
            'status'            => $user->status,
            'is_active'         => (bool) $user->is_active,
            'company_id'        => $companyId,
            'company_name'      => DB::table('companies')->where('id', $companyId)->value('name'),
            // Every company they belong to — deleting the account reaches all
            // of them, not just the one the admin is looking at.
            'companies'         => $companies,
        ];
    }

    /**
     * A permanent delete removes the login itself, so it is refused whenever
     * the account also belongs to a company this admin does not own —
     * otherwise one Company Admin could destroy another's user. Unassigning
     * from this company stays available in that case.
     *
     * @return array{0: bool, 1: string|null}
     */
    private static function hardDeleteGuard(User $user, int $companyId): array
    {
        $adminId = DB::table('companies')->where('id', $companyId)->value('admin_id');

        $outside = DB::table('company_user_assignments as cua')
            ->join('companies as c', 'c.id', '=', 'cua.company_id')
            ->where('cua.user_id', $user->id)
            ->where('c.admin_id', '!=', $adminId)
            ->pluck('c.name');

        if ($outside->isNotEmpty()) {
            return [false, 'This account also belongs to ' . $outside->implode(', ')
                . ', outside your organisation. Remove them from this company instead — '
                . 'deleting the account would destroy another organisation\'s user.'];
        }

        return [true, null];
    }

    /**
     * A compact "how much are they holding here" line per company, for the
     * picker a multi-company user gets before the full summary. Deliberately
     * cheap — six counts a company — so the dialog opens instantly; the real
     * breakdown is impact()'s job once a company is chosen.
     *
     * @param  array<int, int>  $companyIds  companies the admin may act on
     */
    public static function companyWorkload(User $user, array $companyIds): array
    {
        return DB::table('company_user_assignments as cua')
            ->join('companies as c', 'c.id', '=', 'cua.company_id')
            ->where('cua.user_id', $user->id)
            ->whereIn('cua.company_id', $companyIds)
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'cua.status'])
            ->map(function ($row) use ($user) {
                $projectIds = self::companyProjectIds($row->id);

                $projects = DB::table('projects')->where('company_id', $row->id)
                    ->whereNull('deleted_at')
                    ->where(fn ($w) => $w->where('project_manager_id', $user->id)
                        ->orWhere('seller_id', $user->id))
                    ->count();

                $leads = DB::table('leads')->where('company_id', $row->id)
                    ->whereNull('deleted_at')->where('assigned_to', $user->id)->count();

                $tasks = DB::table('tasks')->whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)->count();

                $clients = DB::table('clients')->where('company_id', $row->id)
                    ->whereNull('deleted_at')->where('account_manager', $user->id)->count();

                $tickets = DB::table('support_tickets')->where('company_id', $row->id)
                    ->where('assigned_to', $user->id)->count();

                $team = DB::table('project_team_members')->whereIn('project_id', $projectIds)
                    ->where('user_id', $user->id)->count();

                return [
                    'company_id'   => $row->id,
                    'company_name' => $row->name,
                    'status'       => $row->status,
                    'projects'     => $projects,
                    'leads'        => $leads,
                    'tasks'        => $tasks,
                    'clients'      => $clients,
                    'tickets'      => $tickets,
                    'team'         => $team,
                    'total'        => $projects + $leads + $tasks + $clients + $tickets + $team,
                ];
            })
            ->values()
            ->all();
    }

    /** Active users in this company who could take the work over. */
    public static function reassignCandidates(User $user, int $companyId): array
    {
        return DB::table('company_user_assignments as cua')
            ->join('users as u', 'u.id', '=', 'cua.user_id')
            ->where('cua.company_id', $companyId)
            ->where('cua.status', 'active')
            ->where('u.id', '!=', $user->id)
            ->where('u.is_active', true)
            ->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email', 'u.role_type'])
            ->map(fn ($r) => [
                'id'        => $r->id,
                'name'      => $r->name,
                'email'     => $r->email,
                'role_type' => $r->role_type,
            ])
            ->all();
    }

    // =========================================================================
    // Sections
    // =========================================================================

    private static function projectsManaged(User $user, int $companyId): array
    {
        $q = DB::table('projects')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('project_manager_id', $user->id);

        $active = (clone $q)->whereIn('status', self::PROJECT_ACTIVE)->count();

        return self::section(
            key: 'projects_managed',
            label: 'Projects managed',
            query: $q, nameColumn: 'name', metaColumn: 'status',
            note: self::activeNote($active, (clone $q)->count()),
            onDelete: 'unassigned',
            reassignKey: 'projects',
            blocking: $active,
        );
    }

    private static function projectsSelling(User $user, int $companyId): array
    {
        $q = DB::table('projects')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('seller_id', $user->id);

        $active = (clone $q)->whereIn('status', self::PROJECT_ACTIVE)->count();

        return self::section(
            key: 'projects_selling',
            label: 'Projects they are the seller on',
            query: $q, nameColumn: 'name', metaColumn: 'status',
            note: self::activeNote($active, (clone $q)->count()),
            onDelete: 'unassigned',
            reassignKey: 'projects',
            blocking: $active,
        );
    }

    private static function teamAssignments(User $user, array $projectIds): array
    {
        $q = DB::table('project_team_members as ptm')
            ->join('projects as p', 'p.id', '=', 'ptm.project_id')
            ->whereIn('ptm.project_id', $projectIds)
            ->where('ptm.user_id', $user->id);

        return self::section(
            key: 'team_assignments',
            label: 'Project team memberships',
            query: $q, nameColumn: 'p.name', metaColumn: 'ptm.role_in_project',
            idColumn: 'ptm.id',
            onDelete: 'deleted',
            reassignKey: 'team',
        );
    }

    private static function tasksAssigned(User $user, array $projectIds): array
    {
        $q = DB::table('tasks')->whereIn('project_id', $projectIds)->where('assigned_to', $user->id);
        $open = (clone $q)->whereIn('status', self::TASK_OPEN)->count();

        return self::section(
            key: 'tasks_assigned',
            label: 'Tasks assigned to them',
            query: $q, nameColumn: 'title', metaColumn: 'status',
            note: $open > 0 ? "{$open} still open" : null,
            onDelete: 'unassigned',
            reassignKey: 'tasks',
            blocking: $open,
        );
    }

    private static function tasksCreated(User $user, array $projectIds): array
    {
        $q = DB::table('tasks')->whereIn('project_id', $projectIds)->where('created_by', $user->id);

        return self::section(
            key: 'tasks_created',
            label: 'Tasks they created',
            query: $q, nameColumn: 'title', metaColumn: 'status',
            note: 'Authorship only — the tasks stay, the creator becomes blank',
            onDelete: 'unassigned',
        );
    }

    private static function leadsAssigned(User $user, int $companyId): array
    {
        $q = DB::table('leads')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('assigned_to', $user->id);

        $open = (clone $q)->whereIn('status', self::LEAD_OPEN)->count();

        return self::section(
            key: 'leads_assigned',
            label: 'Leads assigned to them',
            query: $q, nameColumn: 'name', metaColumn: 'status',
            note: $open > 0 ? "{$open} still in play" : null,
            onDelete: 'unassigned',
            reassignKey: 'leads',
            blocking: $open,
        );
    }

    private static function clientsManaged(User $user, int $companyId): array
    {
        $q = DB::table('clients')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('account_manager', $user->id);

        return self::section(
            key: 'clients',
            label: 'Clients they account-manage',
            query: $q, nameColumn: 'name', metaColumn: 'status',
            onDelete: 'unassigned',
            reassignKey: 'clients',
            blocking: (clone $q)->count(),
        );
    }

    private static function supportTickets(User $user, int $companyId): array
    {
        $q = DB::table('support_tickets')->where('company_id', $companyId)
            ->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhere('raised_by', $user->id));

        $open = (clone $q)->where('assigned_to', $user->id)
            ->whereIn('status', self::TICKET_OPEN)->count();

        return self::section(
            key: 'support_tickets',
            label: 'Support tickets raised by or assigned to them',
            query: $q, nameColumn: 'subject', metaColumn: 'status',
            note: $open > 0 ? "{$open} open and assigned to them" : null,
            onDelete: 'unassigned',
            reassignKey: 'support_tickets',
            blocking: $open,
        );
    }

    private static function invoicesCreated(User $user, int $companyId): array
    {
        $q = DB::table('invoices')->where('company_id', $companyId)
            ->where(fn ($w) => $w->where('created_by', $user->id)->orWhere('sent_by', $user->id));

        return self::section(
            key: 'invoices',
            label: 'Invoices they created or sent',
            query: $q, nameColumn: 'invoice_number', metaColumn: 'status',
            note: 'The invoices and their payments stay — only the "created by" name is cleared',
            onDelete: 'unassigned',
        );
    }

    private static function complianceRecords(User $user, array $projectIds): array
    {
        $cases = DB::table('compliance_cases as cc')
            ->join('projects as p', 'p.id', '=', 'cc.project_id')
            ->whereIn('cc.project_id', $projectIds)
            ->where('cc.compliance_officer_id', $user->id);

        $reviews   = DB::table('compliance_reviews')->whereIn('project_id', $projectIds)
            ->where('performed_by_user_id', $user->id)->count();
        $documents = DB::table('compliance_documents')->whereIn('project_id', $projectIds)
            ->where('uploaded_by_user_id', $user->id)->count();

        $extra = array_filter([
            $reviews > 0 ? "{$reviews} reviews performed" : null,
            $documents > 0 ? "{$documents} documents uploaded" : null,
        ]);

        return self::section(
            key: 'compliance',
            label: 'Compliance cases they are the officer on',
            query: $cases, nameColumn: 'p.name', metaColumn: 'cc.status',
            idColumn: 'cc.id',
            note: $extra ? implode(' · ', $extra) : null,
            onDelete: 'unassigned',
        );
    }

    private static function chats(User $user): array
    {
        $threads  = DB::table('chat_participants')->where('user_id', $user->id)->count();
        $messages = DB::table('chat_messages')->where('sender_id', $user->id)->count();

        return self::counted(
            key: 'chats',
            label: 'Chat conversations they take part in',
            total: $threads,
            note: $messages > 0
                ? "{$messages} messages they sent stay in place, but show no sender"
                : null,
            onDelete: 'deleted',
        );
    }

    private static function notifications(User $user): array
    {
        return self::counted(
            key: 'notifications',
            label: 'Notifications addressed to them',
            total: DB::table('notifications')->where('user_id', $user->id)->count(),
            onDelete: 'deleted',
        );
    }

    private static function timesheets(User $user, array $projectIds): array
    {
        $q = DB::table('timesheets as ts')
            ->join('tasks as t', 't.id', '=', 'ts.task_id')
            ->whereIn('t.project_id', $projectIds)
            ->where('ts.user_id', $user->id);

        $hours = (clone $q)->sum('ts.hours_logged');

        return self::counted(
            key: 'timesheets',
            label: 'Timesheet entries they logged',
            total: (clone $q)->count(),
            note: $hours > 0
                ? number_format((float) $hours, 2) . ' logged hours are destroyed with the account'
                : null,
            onDelete: 'deleted',
        );
    }

    // =========================================================================
    // Role-specific extras
    // =========================================================================

    private static function roleExtra(User $user, int $companyId, array $projectIds): ?array
    {
        $rows = match ($user->role_type) {
            'seller'          => self::sellerExtra($user, $companyId),
            'lead_manager'    => self::leadManagerExtra($user, $companyId),
            'project_manager' => self::projectManagerExtra($user, $companyId),
            'company_admin'   => self::companyAdminExtra($user, $companyId),
            default           => self::genericExtra($user, $companyId, $projectIds),
        };

        return $rows ? ['role_type' => $user->role_type, 'rows' => $rows] : null;
    }

    private static function sellerExtra(User $user, int $companyId): array
    {
        $leads = DB::table('leads')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('assigned_to', $user->id);

        return [
            ['label' => 'Total leads',        'value' => (clone $leads)->count()],
            ['label' => 'Won leads',          'value' => (clone $leads)->where('status', 'won')->count()],
            ['label' => 'Lost leads',         'value' => (clone $leads)->where('status', 'lost')->count()],
            ['label' => 'Deals converted to projects', 'value' => DB::table('projects')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('seller_id', $user->id)->count()],
            ['label' => 'Sales targets set for them', 'value' => DB::table('sales_targets')
                ->where('company_id', $companyId)->where('user_id', $user->id)->count()],
        ];
    }

    private static function leadManagerExtra(User $user, int $companyId): array
    {
        // No manager→seller ownership column exists: a Lead Manager oversees
        // every seller in their company, so that is what's reported.
        $sellers = DB::table('company_user_assignments as cua')
            ->join('users as u', 'u.id', '=', 'cua.user_id')
            ->where('cua.company_id', $companyId)
            ->where('u.role_type', 'seller')
            ->count();

        return [
            ['label' => 'Sellers they oversee',  'value' => $sellers],
            ['label' => 'Leads assigned to them', 'value' => DB::table('leads')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('assigned_to', $user->id)->count()],
            ['label' => 'Lead transfers they made', 'value' => DB::table('lead_transfers')
                ->where('company_id', $companyId)
                ->where('transferred_by_user_id', $user->id)->count()],
            ['label' => 'Team sales targets they set', 'value' => DB::table('sales_targets')
                ->where('company_id', $companyId)->where('created_by', $user->id)->count()],
            ['label' => 'Report access grants',  'value' => DB::table('report_access')
                ->where('user_id', $user->id)->count()],
        ];
    }

    private static function projectManagerExtra(User $user, int $companyId): array
    {
        $managedIds = DB::table('projects')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('project_manager_id', $user->id)->pluck('id')->all();

        return [
            ['label' => 'Projects they manage', 'value' => count($managedIds)],
            ['label' => 'Team members under those projects', 'value' => DB::table('project_team_members')
                ->whereIn('project_id', $managedIds)->distinct('user_id')->count('user_id')],
            ['label' => 'Tasks in those projects', 'value' => DB::table('tasks')
                ->whereIn('project_id', $managedIds)->count()],
            ['label' => 'Open tasks in those projects', 'value' => DB::table('tasks')
                ->whereIn('project_id', $managedIds)->whereIn('status', self::TASK_OPEN)->count()],
            ['label' => 'Deliverables in those projects', 'value' => DB::table('deliverables')
                ->whereIn('project_id', $managedIds)->count()],
        ];
    }

    private static function companyAdminExtra(User $user, int $companyId): array
    {
        return [
            ['label' => 'Users in this company', 'value' => DB::table('company_user_assignments')
                ->where('company_id', $companyId)->count()],
            ['label' => 'Users they created', 'value' => DB::table('users')
                ->where('created_by', $user->id)->count()],
            ['label' => 'Projects in this company', 'value' => DB::table('projects')
                ->where('company_id', $companyId)->whereNull('deleted_at')->count()],
            ['label' => 'Leads in this company', 'value' => DB::table('leads')
                ->where('company_id', $companyId)->whereNull('deleted_at')->count()],
            ['label' => 'Clients in this company', 'value' => DB::table('clients')
                ->where('company_id', $companyId)->whereNull('deleted_at')->count()],
        ];
    }

    private static function genericExtra(User $user, int $companyId, array $projectIds): array
    {
        return [
            ['label' => 'Files they uploaded to project folders', 'value' => DB::table('project_folder_files')
                ->whereIn('project_id', $projectIds)->where('uploaded_by', $user->id)->count()],
            ['label' => 'Project comments they wrote', 'value' => DB::table('project_comments')
                ->whereIn('project_id', $projectIds)->where('author_user_id', $user->id)->count()],
            ['label' => 'Deliverables they uploaded', 'value' => DB::table('deliverables')
                ->whereIn('project_id', $projectIds)->where('uploaded_by', $user->id)->count()],
            ['label' => 'Report access grants', 'value' => DB::table('report_access')
                ->where('user_id', $user->id)->count()],
        ];
    }

    // =========================================================================
    // Reassignment
    // =========================================================================

    /**
     * Hand this user's work in $companyId over to other people, one target per
     * bucket. Buckets left out of $targets are untouched.
     *
     * @param  array<string, int>  $targets  bucket => receiving user id
     * @return array<string, int>  bucket => rows moved
     */
    public static function reassign(User $user, int $companyId, array $targets, ?int $actorUserId = null): array
    {
        $projectIds = self::companyProjectIds($companyId);
        $moved = [];

        DB::transaction(function () use ($user, $companyId, $targets, $projectIds, $actorUserId, &$moved) {
            foreach ($targets as $bucket => $toUserId) {
                $moved[$bucket] = match ($bucket) {
                    'projects' => DB::table('projects')->where('company_id', $companyId)
                            ->where('project_manager_id', $user->id)
                            ->update(['project_manager_id' => $toUserId])
                        + DB::table('projects')->where('company_id', $companyId)
                            ->where('seller_id', $user->id)
                            ->update(['seller_id' => $toUserId]),

                    'leads' => DB::table('leads')->where('company_id', $companyId)
                        ->where('assigned_to', $user->id)
                        ->update(['assigned_to' => $toUserId]),

                    'tasks' => DB::table('tasks')->whereIn('project_id', $projectIds)
                        ->where('assigned_to', $user->id)
                        ->update(['assigned_to' => $toUserId]),

                    'clients' => DB::table('clients')->where('company_id', $companyId)
                        ->where('account_manager', $user->id)
                        ->update(['account_manager' => $toUserId]),

                    'support_tickets' => DB::table('support_tickets')->where('company_id', $companyId)
                        ->where('assigned_to', $user->id)
                        ->update(['assigned_to' => $toUserId]),

                    'team' => self::moveTeamMemberships($user, $projectIds, $toUserId),

                    default => 0,
                };
            }

            SystemAuditLog::create([
                'company_id'  => $companyId,
                'user_id'     => $actorUserId,
                'action'      => 'user_workload_reassigned',
                'module_key'  => 'users',
                'entity_type' => 'User',
                'entity_id'   => $user->id,
                'new_values'  => ['targets' => $targets, 'moved' => $moved],
            ]);
        });

        return $moved;
    }

    /**
     * project_team_members is unique on (project_id, user_id), so a membership
     * can only move to someone who isn't already on that project — where they
     * are, the outgoing row is simply dropped instead of duplicating them.
     */
    private static function moveTeamMemberships(User $user, array $projectIds, int $toUserId): int
    {
        $rows = DB::table('project_team_members')
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $user->id)
            ->get(['id', 'project_id']);

        $alreadyOn = DB::table('project_team_members')
            ->whereIn('project_id', $rows->pluck('project_id'))
            ->where('user_id', $toUserId)
            ->pluck('project_id')
            ->all();

        $movedIds   = $rows->reject(fn ($r) => in_array($r->project_id, $alreadyOn))->pluck('id');
        $droppedIds = $rows->filter(fn ($r) => in_array($r->project_id, $alreadyOn))->pluck('id');

        if ($droppedIds->isNotEmpty()) {
            DB::table('project_team_members')->whereIn('id', $droppedIds)->delete();
        }
        if ($movedIds->isNotEmpty()) {
            DB::table('project_team_members')->whereIn('id', $movedIds)->update(['user_id' => $toUserId]);
        }

        return $movedIds->count() + $droppedIds->count();
    }

    // =========================================================================
    // Delete
    // =========================================================================

    /**
     * Permanently remove the account. Irreversible — the caller must have
     * shown impact() and taken an explicit confirmation first.
     *
     * Rows keyed to this user with no foreign key of their own are swept by
     * hand; everything else is left to the SET NULL / CASCADE rules documented
     * at the top of this class.
     */
    public static function purge(User $user, int $companyId, ?int $actorUserId = null): void
    {
        $snapshot = self::impact($user, $companyId);

        DB::transaction(function () use ($user, $companyId, $actorUserId, $snapshot) {
            // No FK on either table — nothing would clear them on its own.
            DB::table('sales_targets')->where('user_id', $user->id)->delete();

            // A transfer row means "this lead was handed to to_user_id", so
            // one pointing at a deleted account has nothing left to say —
            // and to_user_id is NOT NULL, so it can't be blanked either. The
            // other two columns are nullable, so those rows keep their place
            // in the lead's history and just lose the person.
            DB::table('lead_transfers')->where('to_user_id', $user->id)->delete();
            DB::table('lead_transfers')->where('from_user_id', $user->id)
                ->update(['from_user_id' => null]);
            DB::table('lead_transfers')->where('transferred_by_user_id', $user->id)
                ->update(['transferred_by_user_id' => null]);

            // Written before the delete so the row survives the CASCADE on
            // system_audit_logs.user_id — the actor is the admin, not the
            // account being removed, so this entry outlives it.
            SystemAuditLog::create([
                'company_id'  => $companyId,
                'user_id'     => $actorUserId,
                'action'      => 'user_permanently_deleted',
                'module_key'  => 'users',
                'entity_type' => 'User',
                'entity_id'   => $user->id,
                'old_values'  => $snapshot,
            ]);

            CompanyUserAssignment::where('user_id', $user->id)->delete();

            // forceDelete, not delete: User uses SoftDeletes, and a soft
            // delete leaves the row (and therefore every CASCADE above it)
            // exactly where it was — the account would still hold its seat,
            // its email would still occupy the unique index, and nothing the
            // modal warned about would actually happen.
            $user->forceDelete();
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<int, int> */
    private static function companyProjectIds(int $companyId): array
    {
        return DB::table('projects')->where('company_id', $companyId)
            ->whereNull('deleted_at')->pluck('id')->all();
    }

    private static function activeNote(int $active, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }
        $done = $total - $active;

        return "{$active} active · {$done} completed or cancelled";
    }

    /**
     * A section backed by real rows — count plus the first NAME_LIMIT names,
     * so the admin sees what is actually affected rather than only a number.
     */
    private static function section(
        string $key,
        string $label,
        Builder $query,
        string $nameColumn,
        ?string $metaColumn = null,
        ?string $idColumn = null,
        ?string $note = null,
        string $onDelete = 'unassigned',
        ?string $reassignKey = null,
        int $blocking = 0,
    ): array {
        $total = (clone $query)->count();

        $columns = [($idColumn ?? 'id') . ' as row_id', "$nameColumn as row_name"];
        if ($metaColumn) {
            $columns[] = "$metaColumn as row_meta";
        }

        $items = $total === 0 ? [] : (clone $query)->limit(self::NAME_LIMIT)->get($columns)
            ->map(fn ($r) => [
                'id'   => $r->row_id,
                'name' => (string) ($r->row_name ?? '—'),
                'meta' => $metaColumn ? ($r->row_meta ?? null) : null,
            ])
            ->all();

        return [
            'key'            => $key,
            'label'          => $label,
            'total'          => $total,
            'note'           => $note,
            'items'          => $items,
            'more'           => max(0, $total - count($items)),
            'on_delete'      => $onDelete,
            'reassign_key'   => $reassignKey,
            'blocking_count' => $blocking,
        ];
    }

    /** A section that is only a number — nothing worth naming individually. */
    private static function counted(
        string $key,
        string $label,
        int $total,
        ?string $note = null,
        string $onDelete = 'deleted',
    ): array {
        return [
            'key'            => $key,
            'label'          => $label,
            'total'          => $total,
            'note'           => $note,
            'items'          => [],
            'more'           => 0,
            'on_delete'      => $onDelete,
            'reassign_key'   => null,
            'blocking_count' => 0,
        ];
    }
}
