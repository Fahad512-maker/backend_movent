<?php

namespace App\Services\ClientAssistant;

use App\Models\Client;
use App\Models\ClientPaymentMethod;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\ClientPaymentMethodService;
use App\Services\ClientPortalService;
use App\Services\PaymentAssistantService;

/**
 * The ONLY way the Client Portal assistant reaches data.
 *
 * Every method here starts from the authenticated `User` and derives the
 * Client rows that login actually owns — nothing is ever looked up by an id
 * that arrived in a request body, and no method accepts a client_id or
 * company_id it has not first validated against that login. That is the whole
 * point of this class: the model decides what the client MEANT, this decides
 * what they are ALLOWED to see, and the two are kept apart on purpose.
 *
 * It owns no business logic either. Invoice matching, payable-status rules and
 * amount-due arithmetic come from App\Services\PaymentAssistantService; saved
 * cards and charging come from App\Services\ClientPaymentMethodService; the
 * per-project client conversation comes from App\Services\ProjectClientChatService.
 * This is a read/authorize layer over the existing services, not a second copy
 * of them.
 *
 * Module gates are honoured exactly as the rest of the portal honours them
 * (ClientPortalService::isModuleEnabled) — a client whose Invoices module is
 * switched off gets no invoices here either, so the widget can never become a
 * way around a toggle Company Admin set.
 */
class AssistantTools
{
    /** Project statuses that mean "still being worked on". */
    public const ONGOING_STATUSES = ['planning', 'active', 'on_hold'];

    /** Project statuses that mean "finished", including the locked/archived ones. */
    public const FINISHED_STATUSES = ['completed', 'closed', 'approved_locked'];

    // ── Identity ─────────────────────────────────────────────────────────────

    /**
     * Every Client record this portal login owns, regardless of module.
     *
     * Unlike the controllers' permittedClientIds(), this never aborts: the
     * widget has five flows and one disabled module must not 403 the other
     * four. Callers ask for the module they need via clientsFor().
     *
     * @return \Illuminate\Support\Collection<int, Client>
     */
    public static function portalClients(User $user)
    {
        return Client::where('user_id', $user->id)
            ->where('portal_access', true)
            ->with('company:id,name')
            ->get();
    }

    /**
     * This login's Client rows that currently have $moduleKey enabled.
     *
     * @return \Illuminate\Support\Collection<int, Client>
     */
    public static function clientsFor(User $user, string $moduleKey)
    {
        return static::portalClients($user)
            ->filter(fn (Client $c) => ClientPortalService::isModuleEnabled($c, $moduleKey))
            ->values();
    }

    /**
     * The companies this login can act in for a given module — what the widget
     * shows when it has to ask "which company is this about?".
     *
     * @return array<int, array{company_id: int, client_id: int, company_name: string|null}>
     */
    public static function companiesFor(User $user, string $moduleKey): array
    {
        return static::clientsFor($user, $moduleKey)
            ->map(fn (Client $c) => [
                'company_id'   => (int) $c->company_id,
                'client_id'    => (int) $c->id,
                'company_name' => $c->company?->name ?: $c->company_name,
            ])
            ->values()
            ->all();
    }

    /** This login's Client row at one company, for one module — or null. */
    public static function clientAt(User $user, int $companyId, string $moduleKey): ?Client
    {
        return static::clientsFor($user, $moduleKey)
            ->firstWhere('company_id', $companyId);
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    /**
     * Paid / outstanding counts and the due list, for one company.
     *
     * Counts come from the database, never from the length of the trimmed list
     * below — "you have 14 due" with 8 shown is correct; "you have 8 due" when
     * there are 14 is a lie the client would act on.
     *
     * @return array{
     *     paid_count: int, due_count: int, total_due: float, currency: string|null,
     *     due: array<int, array<string, mixed>>, paid: array<int, array<string, mixed>>
     * }
     */
    public static function invoiceSummary(Client $client, int $dueLimit = 8, int $paidLimit = 5): array
    {
        $base = fn () => Invoice::where('client_id', $client->id)
            ->where('company_id', $client->company_id)
            ->whereNotIn('status', ['draft', 'cancelled']);

        $due = $base()
            ->whereIn('status', PaymentAssistantService::PAYABLE_STATUSES)
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invoice $i) => PaymentAssistantService::invoiceCard($i))
            // A 'sent' invoice with nothing left on it is settled in every way
            // that matters to the client, so it is not "due".
            ->filter(fn (array $card) => $card['amount_due'] > 0)
            ->values();

        $paidRows = $base()
            ->where('status', 'paid')
            ->orderByDesc('id')
            ->get();

        return [
            'paid_count' => $paidRows->count(),
            'due_count'  => $due->count(),
            'total_due'  => round((float) $due->sum('amount_due'), 2),
            // One currency per company in practice; taking it off the first due
            // invoice (else the first paid one) beats inventing a symbol.
            'currency'   => $due->first()['currency'] ?? $paidRows->first()?->currency,
            'due'        => $due->take($dueLimit)->values()->all(),
            'paid'       => $paidRows->take($paidLimit)
                ->map(fn (Invoice $i) => PaymentAssistantService::invoiceCard($i))
                ->values()
                ->all(),
        ];
    }

    /**
     * An invoice by the reference the client typed, scoped to this login.
     *
     * Delegates the matching itself to PaymentAssistantService::matchInvoice()
     * so the widget and the full-page assistant resolve "INV-2026-0022",
     * "inv 2026 0022" and "20260022" to the same row.
     */
    public static function findInvoice(User $user, string $reference, ?int $companyId = null): ?Invoice
    {
        $clients = static::clientsFor($user, 'invoices');

        if ($clients->isEmpty()) {
            return null;
        }

        $scoped = $companyId
            ? $clients->where('company_id', $companyId)
            : $clients;

        foreach ($scoped as $client) {
            $invoice = PaymentAssistantService::matchInvoice(
                $reference,
                [(int) $client->id],
                (int) $client->company_id
            );

            if ($invoice) {
                return $invoice->loadMissing('company:id,name');
            }
        }

        return null;
    }

    /**
     * An invoice by id, re-validated against this login. Never trusts the id.
     */
    public static function invoiceById(User $user, int $invoiceId): ?Invoice
    {
        $clientIds = static::clientsFor($user, 'invoices')->pluck('id')->all();

        if (!$clientIds) {
            return null;
        }

        return Invoice::where('id', $invoiceId)
            ->whereIn('client_id', $clientIds)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('company:id,name')
            ->first();
    }

    /** The most recent invoice this login still owes, across their companies. */
    public static function latestUnpaidInvoice(User $user, ?int $companyId = null): ?Invoice
    {
        $clients = static::clientsFor($user, 'invoices');

        if ($companyId) {
            $clients = $clients->where('company_id', $companyId);
        }

        if ($clients->isEmpty()) {
            return null;
        }

        return Invoice::whereIn('client_id', $clients->pluck('id'))
            ->whereIn('status', PaymentAssistantService::PAYABLE_STATUSES)
            ->with('company:id,name')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Invoice $i) => PaymentAssistantService::amountDue($i) > 0);
    }

    // ── Payment methods ──────────────────────────────────────────────────────

    /**
     * The client's usable saved cards for one company.
     *
     * Brand + last four only — ClientPaymentMethod hides the gateway token and
     * customer id at the model level, so nothing further up can leak them.
     *
     * @return array<int, array{id: int, label: string, is_default: bool}>
     */
    public static function savedMethods(Client $client): array
    {
        return ClientPaymentMethodService::forClient((int) $client->id, (int) $client->company_id)
            ->map(fn (ClientPaymentMethod $m) => [
                'id'         => (int) $m->id,
                'label'      => $m->label(),
                'is_default' => (bool) $m->is_default,
            ])
            ->values()
            ->all();
    }

    /** One of this login's own saved cards, or null. Never looked up by id alone. */
    public static function savedMethodById(Client $client, int $methodId): ?ClientPaymentMethod
    {
        return ClientPaymentMethodService::forClient((int) $client->id, (int) $client->company_id)
            ->firstWhere('id', $methodId);
    }

    /** Can this company's gateway vault a card at all? */
    public static function canSaveCards(Client $client): bool
    {
        return ClientPaymentMethodService::supportsSaving((int) $client->company_id, 'stripe');
    }

    // ── Projects ─────────────────────────────────────────────────────────────

    /**
     * This login's projects, already filtered to what the portal shows.
     *
     * notDraft() mirrors Api\Client\ProjectController — a draft/unpaid project
     * is not work yet and the portal never surfaces one, so the assistant must
     * not either.
     *
     * @return \Illuminate\Support\Collection<int, Project>
     */
    public static function projects(User $user, ?int $companyId = null)
    {
        $clients = static::clientsFor($user, 'projects');

        if ($companyId) {
            $clients = $clients->where('company_id', $companyId);
        }

        if ($clients->isEmpty()) {
            return collect();
        }

        return Project::whereIn('client_id', $clients->pluck('id'))
            ->notDraft()
            ->with(['company:id,name', 'projectManager:id,name'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Counts for the one-line answer, computed over the same collection the
     * detail list comes from so the two can never disagree.
     *
     * @return array{total: int, ongoing: int, completed: int, next_deadline: Project|null}
     */
    public static function projectSummary($projects): array
    {
        $ongoing = $projects->filter(fn (Project $p) => in_array($p->status, self::ONGOING_STATUSES, true));

        return [
            'total'         => $projects->count(),
            'ongoing'       => $ongoing->count(),
            'completed'     => $projects->filter(fn (Project $p) => in_array($p->status, self::FINISHED_STATUSES, true))->count(),
            // Soonest upcoming deadline among the live ones — the "natural next
            // detail" the brief asks for, and null when there isn't one rather
            // than a guessed date.
            'next_deadline' => $ongoing->filter(fn (Project $p) => $p->deadline !== null)
                ->sortBy('deadline')
                ->first(),
        ];
    }

    /**
     * The projects this login can actually hold a conversation about.
     *
     * "Talk to Seller" is per-PROJECT (one project = one conversation, see
     * ProjectChatService), so a project with no seller assigned has no
     * counterpart and is left out rather than offered and then apologised for.
     * The seller is eager-loaded because every caller labels the choice with
     * their name.
     *
     * @return \Illuminate\Support\Collection<int, Project>
     */
    public static function projectsWithSeller(User $user, ?int $companyId = null)
    {
        return static::projects($user, $companyId)
            ->filter(fn (Project $p) => $p->seller_id !== null)
            ->load('seller:id,name,custom_role_label,is_active,last_seen_at')
            ->filter(fn (Project $p) => $p->seller && $p->seller->is_active)
            ->values();
    }

    /** Match a project this login owns by (partial) name. Null when ambiguous or absent. */
    public static function findProjectByName(User $user, string $name, ?int $companyId = null): ?Project
    {
        $needle = mb_strtolower(trim($name));

        if ($needle === '') {
            return null;
        }

        $projects = static::projects($user, $companyId);

        $exact = $projects->first(fn (Project $p) => mb_strtolower((string) $p->name) === $needle);

        if ($exact) {
            return $exact;
        }

        $partial = $projects->filter(
            fn (Project $p) => str_contains(mb_strtolower((string) $p->name), $needle)
        )->values();

        // Two candidates is genuinely ambiguous — the caller asks rather than
        // picking one and describing the wrong project confidently.
        return $partial->count() === 1 ? $partial->first() : null;
    }

    /** A project by id, re-validated against this login. */
    public static function projectById(User $user, int $projectId): ?Project
    {
        return static::projects($user)->firstWhere('id', $projectId);
    }

    /**
     * What the assistant is allowed to say about one project.
     *
     * Every value is read off the row. Progress is the same task-ratio the
     * portal's own project screens compute (Api\Client\ProjectController), with
     * the same "a finished project reads 100%" override, so the widget and the
     * page never quote two different numbers for the same project.
     *
     * @return array<string, mixed>
     */
    public static function projectCard(Project $project): array
    {
        $total = $project->tasks()->count();
        $done  = $project->tasks()->where('status', 'completed')->count();

        return [
            'id'           => (int) $project->id,
            'name'         => $project->name,
            'status'       => $project->status,
            'company_name' => $project->company?->name,
            'start_date'   => $project->start_date ? (string) $project->start_date->toDateString() : null,
            'deadline'     => $project->deadline ? (string) $project->deadline->toDateString() : null,
            'manager'      => $project->projectManager?->name,
            'progress'     => in_array($project->status, self::FINISHED_STATUSES, true)
                ? 100
                : ($total > 0 ? (int) round(($done / $total) * 100) : 0),
            'open_tasks'   => max($total - $done, 0),
        ];
    }

    // ── Seller / account manager ─────────────────────────────────────────────

    /**
     * The seller this client actually dealt with, for one company.
     *
     * Order matters and is not arbitrary — the brief asks for "the seller tied
     * to the original lead/deal, not just any available agent":
     *
     *   1. leads.assigned_to on the lead this Client was converted from. This
     *      IS the original deal owner and is the answer whenever it exists.
     *   2. clients.account_manager — the person explicitly named as running
     *      this account when there is no lead behind it.
     *   3. projects.seller_id on their most recent project, which is who has
     *      actually been talking to them since.
     *
     * Never falls through to "some seller at this company". A null here means
     * the caller must say so plainly and offer support instead.
     *
     * @return array{user: User, source: string}|null
     */
    public static function assignedSeller(Client $client): ?array
    {
        $candidates = [];

        if ($client->lead_id) {
            $leadSellerId = Lead::where('id', $client->lead_id)->value('assigned_to');
            if ($leadSellerId) {
                $candidates[] = [(int) $leadSellerId, 'lead'];
            }
        }

        if ($client->account_manager) {
            $candidates[] = [(int) $client->account_manager, 'account_manager'];
        }

        $projectSellerId = Project::where('client_id', $client->id)
            ->whereNotNull('seller_id')
            ->orderByDesc('id')
            ->value('seller_id');

        if ($projectSellerId) {
            $candidates[] = [(int) $projectSellerId, 'project'];
        }

        foreach ($candidates as [$userId, $source]) {
            // Still employed and still active — a deactivated account cannot be
            // handed a client, and telling the client their manager is someone
            // who left is worse than saying there is nobody.
            $user = User::where('id', $userId)
                ->where('is_active', true)
                ->first();

            if ($user) {
                return ['user' => $user, 'source' => $source];
            }
        }

        return null;
    }

    // NOTE: assignedSeller() is the FALLBACK path, not the main one.
    //
    // The hand-off is per-project now (2026-09-30): the assistant asks which
    // project, then opens that project's own 'project_group' conversation in a
    // floating window — see projectsWithSeller() above and
    // Api\Client\AssistantController::openProjectSellerChat(). A client with
    // three projects has three sellers and three conversations, and picking one
    // "account seller" for them put messages in front of the wrong person.
    //
    // The order below still decides the case that has no project to read an
    // answer off: a standalone-invoice client, or one whose only project is
    // still a draft. Those keep opening the per-client Direct Chat
    // (App\Services\ClientSellerChatService), which is why that path survives —
    // it is the reason the project thread was abandoned once before, and it is
    // still the reason it cannot be the only option.
    //
    // The other half of that old objection — a client cannot CREATE a
    // project_group thread (PM-tier/Admin only, ProjectChatService::threadFor)
    // — is handled rather than worked around: a project only reaches the picker
    // once it has an assigned seller, and assigning one already creates the
    // thread (ProjectSellerAssignmentService::assign → addSeller). If one is
    // somehow still missing, Api\Client\ProjectChatController reports can_send
    // false and the window says so instead of failing on send.
}
