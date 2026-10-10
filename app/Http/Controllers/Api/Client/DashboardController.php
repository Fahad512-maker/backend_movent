<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAiServiceBatch;
use App\Models\ClientAiServiceBatchItem;
use App\Models\CompanyModule;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Services\ClientAiServiceBatchService;
use App\Services\ClientPortalService;
use App\Services\ClientServiceRecommendationService;
use App\Services\ClientServiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // All client records for this user that have portal access
        $clients   = Client::where('user_id', $userId)
            ->where('portal_access', true)
            ->with('company')
            ->get();

        if ($clients->isEmpty()) {
            return ApiResponse::error('Client not found', 404);
        }

        $clientIds = $clients->pluck('id')->toArray();

        // Use the first (primary) client's company for module checking
        $primaryClient  = $clients->first();
        $companyModules = CompanyModule::where('company_id', $primaryClient->company_id)
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->toArray();

        $hasModule = fn(string $key): bool => in_array($key, $companyModules);

        // Per-client toggles (Company Admin's Portal tab) — same rule
        // Api\Client\Concerns\ScopesToPermittedClient enforces on every other
        // Client endpoint. A company-wide purchase alone (the $hasModule
        // check above) isn't enough: Admin can also turn a module off for one
        // client only.
        $invoicesEnabled  = $clients->contains(fn (Client $c) => ClientPortalService::isModuleEnabled($c, 'invoices'));
        $projectsEnabled  = $clients->contains(fn (Client $c) => ClientPortalService::isModuleEnabled($c, 'projects'));
        $supportEnabled   = $clients->contains(fn (Client $c) => ClientPortalService::isModuleEnabled($c, 'support'));

        // Only the client_ids that still have the module on — what the
        // browsable LISTS below are drawn from.
        $invoiceClientIds = $clients->filter(fn (Client $c) => ClientPortalService::isModuleEnabled($c, 'invoices'))->pluck('id')->toArray();
        $projectClientIds = $clients->filter(fn (Client $c) => ClientPortalService::isModuleEnabled($c, 'projects'))->pluck('id')->toArray();

        // Headline COUNTS deliberately span every client this login owns,
        // enabled or not, so a disabled module's dashboard tile still shows
        // its real number instead of a misleading 0 (explicit product
        // decision, 2026-09-03). The tile is inert in that state — the
        // frontend drops its link — and the detail lists below stay scoped to
        // $invoiceClientIds/$projectClientIds, so turning a module off still
        // withholds every invoice number, project name, amount and due date.
        // Counts only; never rows.
        $statsInvoices = Invoice::whereIn('client_id', $clientIds)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['id', 'total_amount', 'paid_amount', 'status']);

        $paidInvoices    = $statsInvoices->where('status', 'paid');
        $pendingInvoices = $statsInvoices->whereIn('status', ['sent', 'overdue', 'partially_paid']);

        $paidCount     = $paidInvoices->count();
        $paidAmount    = (float) $paidInvoices->sum('total_amount');
        $pendingCount  = $pendingInvoices->count();
        $pendingAmount = (float) $pendingInvoices->sum(fn ($i) => max(0, $i->total_amount - $i->paid_amount));
        $overdueCount  = $statsInvoices->where('status', 'overdue')->count();

        // The browsable list — scoped to the enabled clients only.
        $recentInvoices = empty($invoiceClientIds) ? [] : Invoice::whereIn('client_id', $invoiceClientIds)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'invoice_number', 'total_amount', 'paid_amount',
                   'currency', 'status', 'due_date', 'created_at'])
            ->toArray();

        // ── Projects ─────────────────────────────────────────────────────────
        // Counts across every client (see the note above); the recent list
        // only across the ones with the module still enabled.
        $projects          = Project::whereIn('client_id', $clientIds)->notDraft()->get(['id', 'status']);
        $totalProjects     = $projects->count();
        $completedProjects = $projects->where('status', 'completed')->count();
        // "Pending" = not started/paused yet; "Ongoing" = actively being
        // worked on right now — split out of what was previously one
        // combined planning/active/on_hold bucket.
        $pendingProjects   = $projects->whereIn('status', ['planning', 'on_hold'])->count();
        $ongoingProjects   = $projects->where('status', 'active')->count();

        $recentProjects = empty($projectClientIds) ? [] : Project::whereIn('client_id', $projectClientIds)
            ->notDraft()
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id', 'name', 'status', 'deadline', 'created_at'])
            ->toArray();

        // The dashboard hero's "what should this client see next" signal —
        // an active/in-flight project wins over a merely-recent one, so the
        // hero reflects what the client is currently paying attention to.
        // Reuses the same client scoping as $recentProjects above (never the
        // unscoped $clientIds) so this respects the Projects module toggle.
        $heroProject = empty($projectClientIds) ? null : (
            Project::whereIn('client_id', $projectClientIds)
                ->notDraft()
                ->whereIn('status', ['active', 'planning', 'on_hold'])
                ->orderByDesc('created_at')
                ->with('companyService')
                ->first()
            ?? Project::whereIn('client_id', $projectClientIds)
                ->notDraft()
                ->orderByDesc('created_at')
                ->with('companyService')
                ->first()
        );

        // ── Support tickets ───────────────────────────────────────────────────
        $openTickets = $supportEnabled
            ? SupportTicket::where('raised_by', $userId)->whereIn('status', ['open', 'in_progress'])->count()
            : 0;

        // Every non-cancelled project this client can get recommendations
        // for — lets the frontend offer a switcher when there's more than
        // one, and lets a client with projects in different categories (e.g.
        // "Installment Software" + "Point of Sale") pick which project's
        // recommendation set to view (see ClientServiceRecommendationService
        // class docblock: recommendations are scoped per project, never
        // shared across a client's projects).
        $recommendationProjects = empty($projectClientIds) ? collect() : Project::whereIn('client_id', $projectClientIds)
            ->notDraft()
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->with('companyService')
            ->get(['id', 'name', 'status', 'company_service_id', 'created_at']);

        // An explicit ?project_id= picks which project's recommendations to
        // show; it must be one of this client's own (non-cancelled) projects
        // or it's silently ignored — never trust a foreign id straight off
        // the query string. No selection (the normal case) keeps the exact
        // same "what matters most" project the hero banner already used
        // before per-project recommendations existed.
        $requestedProjectId = $request->integer('project_id') ?: null;
        $selectedProject = ($requestedProjectId ? $recommendationProjects->firstWhere('id', $requestedProjectId) : null)
            ?? $heroProject;

        $aiServices = self::aiServicesFor($primaryClient, $selectedProject?->id);

        return ApiResponse::success([
            'stats' => [
                'total_invoices'     => $statsInvoices->count(),
                'paid_count'         => $paidCount,
                'paid_amount'        => $paidAmount,
                'pending_count'      => $pendingCount,
                'pending_amount'     => $pendingAmount,
                'overdue_count'      => $overdueCount,
                'total_projects'     => $totalProjects,
                'pending_projects'   => $pendingProjects,
                'ongoing_projects'   => $ongoingProjects,
                'completed_projects' => $completedProjects,
                'open_tickets'       => $openTickets,
            ],
            'recent_invoices' => $recentInvoices,
            'recent_projects' => $recentProjects,
            'modules'         => $companyModules,
            // Two distinct questions the dashboard has to answer per module,
            // which 'modules' above cannot:
            //
            //   portal_available   — does the tile exist at all? (company
            //                        purchased something that unlocks it)
            //   portal_permissions — is the tile clickable? (the above AND
            //                        the Admin's per-client toggle)
            //
            // The frontend used to answer the first one itself by looking for
            // 'projects' in 'modules', which got it wrong for a company that
            // bought client_portal without the standalone Projects module —
            // PORTAL_TO_COMPANY says client_portal unlocks it, so the sidebar
            // showed Projects while the dashboard hid its tiles. Deciding
            // both here keeps that mapping in one place.
            'portal_available' => [
                'invoices' => ClientPortalService::isModuleAvailable($primaryClient->company_id, 'invoices'),
                'projects' => ClientPortalService::isModuleAvailable($primaryClient->company_id, 'projects'),
                'support'  => ClientPortalService::isModuleAvailable($primaryClient->company_id, 'support'),
            ],
            'portal_permissions' => [
                'invoices' => $invoicesEnabled,
                'projects' => $projectsEnabled,
                'support'  => $supportEnabled,
            ],
            // THIS project's own persisted batch of up to 10 (see
            // App\Services\ClientAiServiceBatchService) — $selectedProject
            // above, defaulting to the same "most relevant project" the hero
            // banner always used. Generated the moment one of this project's
            // invoices was first paid; real, tracked 'status' per item, not
            // just a name/summary. Until this project's first qualifying paid
            // invoice, no batch exists yet: falls back to a live-generated
            // list scoped to this same project exactly as before, so this
            // section is never empty for a brand-new project.
            'ai_services' => $aiServices,
            // Every project a client could switch the section above to —
            // omitted (empty) for a client with 0 or 1 recommendable
            // projects, since there's nothing to switch between.
            'recommendation_projects' => $recommendationProjects->map(fn (Project $p) => [
                'id'   => $p->id,
                'name' => $p->name,
            ])->values()->all(),
            'selected_project_id' => $selectedProject?->id,
            // Drives the dashboard's personalized hero banner. Deliberately
            // just the raw facts (project name + category) — the frontend
            // owns the headline copy and visual treatment per category (see
            // components/client/ai-services/categoryStyle.ts, the same
            // bucket list the AI-suggestions cards already use), so there is
            // exactly one place that maps a category to an icon/image/label.
            // Reflects $selectedProject — the SAME project 'ai_services'
            // above is scoped to — so switching projects updates the hero
            // copy ("Recommended for your X project") and the cards below it
            // together, never one without the other.
            'hero' => [
                'project_name' => $selectedProject?->name,
                'is_active'    => $selectedProject?->status === 'active',
                // companyService is the authoritative category when a
                // project is explicitly tagged; otherwise this reads the
                // SAME normalized domain the recommendation list below was
                // ranked against (see ClientServiceRecommendationService::
                // resolve()) — one shared context, so the hero and the cards
                // can never disagree about what this client's project is
                // about. Null renders the generic "grow your business" hero
                // — never invented.
                'category'     => $selectedProject?->companyService?->presentation()['group']
                    ?? ClientServiceRecommendationService::primaryCategoryFor($primaryClient, projectId: $selectedProject?->id),
            ],
        ]);
    }

    /**
     * @return array<int, array{id: ?int, key: string, name: string, category: string, summary: string, timeline: string, status: string}>
     */
    private static function aiServicesFor(Client $client, ?int $projectId): array
    {
        $activeBatch = ClientAiServiceBatch::where('client_id', $client->id)->forProject($projectId)->active()->with('items')->first();

        if ($activeBatch) {
            return $activeBatch->items->map(fn (ClientAiServiceBatchItem $item) => [
                'id'       => $item->id,
                'key'      => $item->key,
                'name'     => $item->name,
                'category' => $item->category,
                'summary'  => $item->summary,
                'timeline' => $item->timeline,
                'status'   => $item->status,
            ])->values()->all();
        }

        // Bootstrap fallback for this project's client with no batch of its
        // own yet — grounded in THIS project's own context + this company's
        // real service catalogue (see ClientServiceRecommendationService),
        // rather than the old company-wide, keyword-blind catalog(). Same
        // default count (10) as the persisted batch (BATCH_SIZE in
        // ClientAiServiceBatchService) — this bootstrap list IS what the
        // client sees before their first batch exists, so it must match the
        // "initially show 10" rule, not a smaller ad-hoc count.
        return collect(ClientServiceRecommendationService::recommend($client, projectId: $projectId))
            ->map(fn (array $service) => $service + ['id' => null, 'status' => 'available'])
            ->values()
            ->all();
    }


    public function requestService(Request $request): JsonResponse
    {
        $clients = Client::where('user_id', $request->user()->id)
            ->where('portal_access', true)
            ->with('company')
            ->get();

        if ($clients->isEmpty()) {
            return ApiResponse::error('Client not found', 404);
        }

        $client = $clients->first();

        // Which project this request is FOR — the same id the dashboard
        // showed the client this card under (see index()'s
        // recommendation_projects/selected_project_id). Must be one of this
        // login's own (non-cancelled) projects or it's ignored; absent
        // entirely falls back to this client's "most relevant project"
        // heuristic, i.e. exactly the old client-wide behaviour, so an older
        // frontend build that never sends project_id keeps working.
        $projectId = $request->integer('project_id') ?: null;
        if ($projectId && !Project::where('client_id', $client->id)->where('id', $projectId)->exists()) {
            $projectId = null;
        }
        $projectId ??= ClientServiceRecommendationService::primaryProjectIdFor($client);

        $activeBatch = ClientAiServiceBatch::where('client_id', $client->id)->forProject($projectId)->active()->first();

        if ($activeBatch) {
            [$service, $notes, $error] = $this->requestBatchItem($request, $activeBatch);
        } else {
            // Bootstrap fallback — same as before this feature existed: no
            // persisted batch yet for this project, so just validate against
            // the live, project-scoped recommendation list and notify;
            // nothing to persist against.
            [$service, $notes, $error] = $this->requestFallbackCatalogService($request, $client, $projectId);
        }

        if ($error) {
            return $error;
        }

        // Reuses the exact same request-creation path "Grow Your Business
        // With Us" already uses (ClientServiceRequestService) instead of the
        // notification-only shortcut this used to be — a "Get Service" click
        // now raises a real ServiceRequest (+ Lead) that shows in the
        // company's Services → Client Requests, correctly scoped to this
        // client's own company, on top of the same bell + email. These AI
        // suggestions aren't tied to a saved CompanyService catalogue row, so
        // $service is null and the request is identified by name only — same
        // as "Other IT Services" on the Grow Your Business flow.
        //
        // The dedupe check inside create() (an already-open request for the
        // same name) is what stops a double-click or a retried API call from
        // raising — and re-emailing — the same request twice.
        $serviceRequest = ClientServiceRequestService::create(
            $client,
            null,
            $service['name'],
            'new_project',
            $notes !== '' ? $notes : null,
            null,
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success([
            'service_key'         => $service['key'],
            'service_name'        => $service['name'],
            'service_request_id'  => $serviceRequest->id,
            'lead_id'             => $serviceRequest->lead_id,
        ], 'Service request sent to admin');
    }

    /**
     * @return array{0: ?array, 1: string, 2: ?JsonResponse}
     */
    private function requestBatchItem(Request $request, ClientAiServiceBatch $batch): array
    {
        $validated = $request->validate([
            'service_key' => ['required', 'string'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $item = ClientAiServiceBatchItem::where('batch_id', $batch->id)
            ->where('key', $validated['service_key'])
            ->first();

        if (!$item) {
            return [null, '', ApiResponse::error('Service not found', 404)];
        }

        if ($item->status === 'taken') {
            return [null, '', ApiResponse::error('This service has already been fulfilled.', 422)];
        }

        $notes = trim((string) ($validated['notes'] ?? ''));
        ClientAiServiceBatchService::requestItem($item, $notes ?: null);

        return [
            ['key' => $item->key, 'name' => $item->name, 'category' => $item->category, 'timeline' => $item->timeline],
            $notes,
            null,
        ];
    }

    /**
     * @return array{0: ?array, 1: string, 2: ?JsonResponse}
     */
    private function requestFallbackCatalogService(Request $request, Client $client, ?int $projectId): array
    {
        // Same source, same default count (10), and (thanks to its
        // signature-keyed cache) normally the same actual list as
        // aiServicesFor()'s bootstrap path above — a client requesting a key
        // must be validated against exactly what the dashboard just showed
        // them for THIS project.
        $services = collect(ClientServiceRecommendationService::recommend($client, projectId: $projectId));

        $validated = $request->validate([
            'service_key' => ['required', 'string', Rule::in($services->pluck('key')->all())],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $service = $services->firstWhere('key', $validated['service_key']);
        $notes = trim((string) ($validated['notes'] ?? ''));

        return [$service, $notes, null];
    }
}
