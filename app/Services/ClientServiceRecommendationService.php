<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAiServiceBatchItem;
use App\Models\CompanyService;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "What service should we suggest this client next" — the single place both
 * App\Services\ClientAiServiceBatchService (persisted per-client-PER-PROJECT
 * batch, tied to the invoice/payment flow) and Api\Client\DashboardController
 * (both the suggestion list AND the hero banner) get their content from.
 *
 * Everything here is scoped to client_id + project_id, never client_id alone
 * — a client with an "Installment Software" project and a separate "Point of
 * Sale" project gets two entirely independent recommendation sets, caches,
 * and purchased/shown-service exclusion lists (see buildContext()). A null
 * project_id (no project yet, or none resolvable) is its own distinct scope,
 * not a fallthrough that leaks into a real project's context.
 *
 * The whole point of this class is CONTEXT NORMALIZATION happening exactly
 * once per (client, project), shared by everything downstream — see
 * resolve(). A project like "book management" is first classified into a
 * normalized {primary_category, keywords} pair (normalizeContext()) —
 * generically, via the same catalogue keyword match already used elsewhere
 * in this app when it recognizes an IT-agency vertical (Web/Mobile/Design/
 * ...), or via one small AI call when it doesn't (so "book management" reads
 * as "Publishing", not left as meaningless raw text). Every recommendation
 * and the hero banner then read off that SAME normalized value — they can
 * never disagree with each other, and nothing here is hardcoded to any one
 * domain (publishing, retail, healthcare, ... are all just words the
 * classifier or the AI produces).
 *
 * Ranking is always grounded, but the company's own catalogue size never
 * caps the list:
 *   - If the company has configured its own CompanyService catalogue, those
 *     real, enabled services are ranked and shown FIRST — an id outside that
 *     list is never substituted in for this part, even if AI returns one.
 *   - If that alone doesn't reach the target count (including when the
 *     company has configured no catalogue at all), the remainder is topped
 *     up with AI-generated suggestions — still required to name the
 *     normalized domain as their own category and clear MIN_RELEVANCE, and
 *     never a duplicate of anything already purchased or already shown FOR
 *     THIS PROJECT.
 *
 * Quality over quantity: nothing below MIN_RELEVANCE is ever shown just to
 * pad the count. But once a real project exists, an empty result is only
 * ever a SIGNAL, never the end state a client sees — see categoryFallback():
 * if the AI ranking/generation genuinely comes back empty (model down,
 * erroring, or everything scored below the bar), a category-steered fallback
 * list fills the section instead of leaving it blank.
 */
class ClientServiceRecommendationService
{
    private const CACHE_PREFIX = 'client_portal:service_recs:';
    private const CACHE_TTL_HOURS = 12;
    // Short on purpose — an empty result now only happens after the in-
    // generation retry above already failed twice, so a further transient
    // miss is rare; this just keeps recovery quick rather than pinning
    // "nothing relevant" for a full hour.
    private const EMPTY_RESULT_TTL_MINUTES = 10;
    private const TIMEOUT_SECONDS = 12;
    private const MAX_TOKENS = 1400;

    // A service must clear this to be shown at all — better to show 3 real
    // suggestions than pad to 6 with anything merely tangential. Kept
    // deliberately high: a service dressed in domain-adjacent wording but
    // whose actual substance is a generic business function (data
    // standardization, workflow automation, ...) should land below this,
    // not just above it because one keyword happened to overlap.
    private const MIN_RELEVANCE = 0.75;

    /**
     * @param  ?int  $projectId  Which of the client's own projects to scope
     *                           the recommendation set to. Null resolves to
     *                           this client's own "most relevant project"
     *                           heuristic (see primaryProject()) — the same
     *                           behaviour this had before per-project scoping
     *                           existed. A project_id that doesn't belong to
     *                           this client, or is cancelled, is treated the
     *                           same as null rather than erroring.
     * @return array<int, array{key: string, name: string, category: string, summary: string, timeline: string}>
     */
    public static function recommend(Client $client, int $count = 10, ?string $focusContext = null, ?int $projectId = null): array
    {
        return self::resolve($client, $count, $focusContext, $projectId)['items'];
    }

    /**
     * The SAME normalized domain the recommendation list above was ranked
     * against — e.g. "Publishing" for a book project — so the dashboard
     * hero and the recommendation cards can never disagree (both read off
     * one shared, cached resolution rather than being derived twice).
     */
    public static function primaryCategoryFor(Client $client, int $count = 10, ?int $projectId = null): ?string
    {
        return self::resolve($client, $count, null, $projectId)['primary_category'];
    }

    // The id of the client's own "most relevant project" — used only where
    // the caller has no specific project of its own to scope against yet
    // (e.g. App\Services\ClientAiServiceBatchService resolving which project
    // an invoice with no project_id of its own should be attributed to).
    public static function primaryProjectIdFor(Client $client, int $count = 10): ?int
    {
        return self::resolve($client, $count, null, null)['project_id'];
    }

    /**
     * @return array{items: array[], primary_category: ?string, project_id: ?int}
     */
    private static function resolve(Client $client, int $count, ?string $focusContext, ?int $projectId = null): array
    {
        try {
            $context = self::buildContext($client, $projectId);
            // Per (client, project) — never just per client. Two projects in
            // different categories must never read or overwrite each other's
            // cached recommendation set (see class docblock).
            $cacheKey = self::CACHE_PREFIX . $client->id . ':' . ($context['project_id'] ?? 'none');
            $signature = self::signature($context, $count, $focusContext);

            $cached = Cache::get($cacheKey);
            $result = (is_array($cached) && ($cached['signature'] ?? null) === $signature)
                ? Arr::only($cached, ['items', 'primary_category'])
                : null;

            if ($result === null) {
                $result = self::generate($context, $count, $focusContext);

                // An intentionally EMPTY result ("nothing relevant") is a
                // real, stable answer worth caching too — just for a much
                // shorter window, in case it was a transient AI outage rather
                // than a genuine lack of relevant services.
                if ($result['items']) {
                    Cache::put($cacheKey, $result + ['signature' => $signature], now()->addHours(self::CACHE_TTL_HOURS));
                } else {
                    Cache::put($cacheKey, $result + ['signature' => $signature], now()->addMinutes(self::EMPTY_RESULT_TTL_MINUTES));
                }

                // The "reason" a service was suggested, kept for admin/
                // debugging use per product spec, never shown to the client.
                Log::info('[service-recs] generated', [
                    'client_id'        => $client->id,
                    'project'          => $context['project_text'],
                    'primary_category' => $result['primary_category'],
                    'items'            => collect($result['items'])->map(fn (array $i) => [
                        'name' => $i['name'] ?? null, 'reason' => $i['reason'] ?? null,
                    ])->all(),
                ]);
            }

            return [
                'items'            => collect($result['items'])->map(fn (array $i) => Arr::except($i, ['reason']))->values()->all(),
                'primary_category' => $result['primary_category'],
                'project_id'       => $context['project_id'],
            ];
        } catch (\Throwable $e) {
            Log::warning('[service-recs] recommend failed for client ' . $client->id . ': ' . $e->getMessage());

            return ['items' => [], 'primary_category' => null, 'project_id' => null];
        }
    }

    /**
     * Gathers everything the ranking needs from real data for ONE project —
     * the specific one given, or (when none is given) this client's own
     * "most relevant project" heuristic — what that project is actually
     * about (name + description, not just a title fragment), what the client
     * already owns OR has already been shown FOR THIS PROJECT, and what this
     * company can actually sell. Nothing here reads or excludes based on the
     * client's OTHER projects: a Finance project and an E-commerce project
     * for the same client never share a scope, an exclusion list, or a
     * cached result (see class docblock).
     */
    private static function buildContext(Client $client, ?int $projectId): array
    {
        $companyId = $client->company_id;

        $primary = null;

        if ($projectId) {
            $primary = Project::where('client_id', $client->id)
                ->where('id', $projectId)
                ->where('status', '!=', 'cancelled')
                ->with('companyService:id,slug')
                ->first(['id', 'name', 'description', 'status', 'company_service_id', 'created_at']);
        }

        if (!$primary) {
            // No specific project requested, or the id given didn't resolve
            // to a real, non-cancelled project of this client — fall back to
            // the same "which project matters most" heuristic used before
            // per-project scoping existed.
            $projects = Project::where('client_id', $client->id)
                ->where('status', '!=', 'cancelled')
                ->with('companyService:id,slug')
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'description', 'status', 'company_service_id', 'created_at']);

            $primary = self::primaryProject($projects);
        }

        $resolvedProjectId = $primary?->id;

        $projectText = trim((string) collect([$primary?->name, $primary?->description])->filter()->implode('. '));

        $categoryHint = self::categoryOf($primary);

        // Every batch item ever generated for THIS client+project, any
        // generation, any status — the full "never repeat" contract (see
        // class docblock): a 'taken' item is purchased, but everything else
        // ever shown here must not resurface either, on top of the project's
        // own explicit service tag. A repeatable real service (e.g. a
        // retainer) is exempted from both: the client may reasonably be
        // offered — and shown again — the same one.
        $priorItems = ClientAiServiceBatchItem::whereHas('batch', function ($q) use ($client, $resolvedProjectId) {
                $q->where('client_id', $client->id);
                $resolvedProjectId
                    ? $q->where('project_id', $resolvedProjectId)
                    : $q->whereNull('project_id');
            })
            ->with('companyService:id,is_repeatable')
            ->get(['id', 'batch_id', 'company_service_id', 'name', 'status']);

        $nonRepeatablePrior = $priorItems->filter(fn (ClientAiServiceBatchItem $i) => !($i->companyService?->is_repeatable ?? false));

        $ownedServiceIds = collect([$primary?->company_service_id])
            ->filter()
            ->merge($nonRepeatablePrior->pluck('company_service_id')->filter())
            ->unique()
            ->values()
            ->all();

        $ownedNames = array_values(array_unique(array_merge(
            $ownedServiceIds ? CompanyService::whereIn('id', $ownedServiceIds)->pluck('name')->all() : [],
            $nonRepeatablePrior->pluck('name')->all()
        )));

        $available = CompanyService::where('company_id', $companyId)
            ->enabled()
            ->forDisplay()
            ->get()
            ->whereNotIn('id', $ownedServiceIds)
            ->values();

        return [
            'client_id'     => $client->id,
            'project_id'    => $resolvedProjectId,
            'project_name'  => $primary?->name,
            'project_text'  => $projectText,
            'category_hint' => $categoryHint,
            'owned_names'   => $ownedNames,
            'available'     => $available,
        ];
    }

    /**
     * Priority order from the product spec: an active project wins outright;
     * otherwise the most recently touched in-flight (planning/on_hold) one;
     * otherwise — with more than one candidate and none in flight — whichever
     * catalogue category recurs most often across the client's history, so a
     * client with three website projects and one one-off logo reads as a
     * website client first. Falls back to simply the most recent project.
     */
    private static function primaryProject(Collection $projects): ?Project
    {
        if ($projects->isEmpty()) {
            return null;
        }

        $active = $projects->firstWhere('status', 'active');
        if ($active) {
            return $active;
        }

        $inFlight = $projects->first(fn (Project $p) => in_array($p->status, ['planning', 'on_hold'], true));
        if ($inFlight) {
            return $inFlight;
        }

        if ($projects->count() > 1) {
            $byFrequency = self::mostFrequentCategoryProject($projects);
            if ($byFrequency) {
                return $byFrequency;
            }
        }

        return $projects->first();
    }

    private static function mostFrequentCategoryProject(Collection $projects): ?Project
    {
        $counts = $projects->map(fn (Project $p) => self::categoryOf($p))->filter()->countBy();

        if ($counts->isEmpty()) {
            return null;
        }

        $topCategory = $counts->sortDesc()->keys()->first();

        return $projects->first(fn (Project $p) => self::categoryOf($p) === $topCategory);
    }

    /**
     * The catalogue GROUP behind a project — the tag it was explicitly built
     * against when present (exact), otherwise a best-effort keyword read
     * against the built-in IT-agency verticals (Web/Mobile/Design/...). Only
     * ever a strong, exact signal; anything this misses (most non-IT domains)
     * is left null and picked up by normalizeContext()'s AI classification
     * instead of being guessed at here.
     */
    private static function categoryOf(?Project $project): ?string
    {
        if (!$project) {
            return null;
        }

        if ($project->companyService?->slug) {
            return ServiceCatalog::get($project->companyService->slug)['group'] ?? null;
        }

        $slugs = ServiceCatalog::detectSlugs($project->name . ' ' . (string) $project->description);

        return $slugs ? (ServiceCatalog::get($slugs[0])['group'] ?? null) : null;
    }

    private static function signature(array $context, int $count, ?string $focusContext): string
    {
        return md5(json_encode([
            $context['project_id'],
            $context['project_text'],
            $context['owned_names'],
            $context['available']->pluck('id')->all(),
            $count,
            $focusContext,
        ]));
    }

    /**
     * @return array{items: array[], primary_category: ?string}
     */
    private static function generate(array $context, int $count, ?string $focusContext): array
    {
        // No project at all yet — there is nothing to personalize against,
        // so this is a different situation from "has a project but nothing
        // scored highly enough" (which correctly returns empty). Show a
        // sensible default/starter set instead: the company's own real
        // catalogue when it has one, or a well-rounded general list when it
        // doesn't. The moment a real project exists, every call above this
        // one already carries its text, and normal domain-specific ranking
        // (with its relevance bar) takes back over automatically.
        if ($context['project_text'] === '') {
            return ['items' => self::defaultServices($context, $count), 'primary_category' => null];
        }

        $normalized = self::normalizeContext($context);

        $items = $context['available']->isNotEmpty()
            ? self::rankCatalog($context, $normalized, $count, $focusContext)
            : [];

        // The company's own real/manually-configured catalogue always takes
        // priority and is never padded with anything below the relevance bar
        // — but its size must never CAP the section either. If it doesn't
        // have enough genuinely relevant services of its own to reach
        // $count, the gap is filled with AI-generated, still domain-steered,
        // still relevance-gated suggestions — the same mechanism a company
        // with no catalog at all already gets, just topping up rather than
        // replacing.
        if (count($items) < $count) {
            $exclude = collect($items)->pluck('name')->all();
            $items = array_merge($items, self::rankFreeform($context, $normalized, $count - count($items), $focusContext, $exclude));
        }

        // A real project exists but AI ranking/generation came back with
        // nothing at all — a down/erroring model far more often than a
        // genuine "zero relevant services" verdict. An empty section here
        // reads as broken, not as "nothing relevant" — show a category-
        // steered fallback list instead (product requirement: never an empty
        // recommendation section once a project is known).
        if (!$items) {
            $items = self::categoryFallback($context, $normalized, $count);
        }

        return ['items' => $items, 'primary_category' => $normalized['primary_category']];
    }

    /**
     * The starter set shown before the client has any project to
     * personalize against — the company's own real, enabled services
     * (already ordered featured-first by buildContext()'s forDisplay()) when
     * it has configured any, topped up to $count with the same well-rounded
     * general IT catalogue App\Services\AiServiceCatalogService has always
     * used as its static baseline if the real catalogue alone isn't enough.
     * Deliberately not AI-generated: with no project context at all there is
     * nothing for a model to reason about, so this stays a plain, cheap,
     * always-available list rather than a wasted API call.
     */
    private static function defaultServices(array $context, int $count): array
    {
        $items = $context['available']
            ->take($count)
            ->map(function (CompanyService $s) {
                $presentation = $s->presentation();

                return [
                    'key'                => $presentation['slug'] ?? ('company_service_' . $presentation['id']),
                    'name'               => $presentation['name'],
                    'category'           => $presentation['group'],
                    'summary'            => $presentation['short_description'] ?: 'A service offered by your project team.',
                    'timeline'           => 'Upon review',
                    'company_service_id' => $presentation['id'],
                    'reason'             => 'Default starter service — no project yet to personalize against.',
                ];
            })
            ->values()
            ->all();

        if (count($items) >= $count) {
            return $items;
        }

        $usedNames = collect($items)->pluck('name')->map(fn ($n) => strtolower($n))->all();

        $filler = collect(AiServiceCatalogService::fallbackCatalog($count))
            ->filter(fn (array $s) => !in_array(strtolower($s['name']), $usedNames, true))
            ->map(fn (array $s) => $s + ['company_service_id' => null, 'reason' => 'Default starter service — no project yet to personalize against.'])
            ->values()
            ->all();

        return array_merge($items, array_slice($filler, 0, $count - count($items)));
    }

    /**
     * The last resort for a project that DOES exist but for which AI ranking
     * and AI freeform generation both came back with nothing (see generate())
     * — a plain, static, category-steered list rather than an empty section.
     * Matched against the normalized domain (falling back to the exact
     * category-tag hint when the domain itself couldn't be classified); an
     * unrecognized domain gets the same general-purpose IT list
     * defaultServices() uses when there is no project context at all, rather
     * than guessing.
     */
    private static function categoryFallback(array $context, array $normalized, int $count): array
    {
        $category = $normalized['primary_category'] ?? $context['category_hint'];
        $items = self::fallbackItemsFor($category);

        $avoid = array_map('strtolower', $context['owned_names']);

        return collect($items)
            ->filter(fn (array $i) => !in_array(strtolower($i['name']), $avoid, true))
            ->take($count)
            ->map(fn (array $i) => [
                'key'                => self::slugify($i['name']),
                'name'               => $i['name'],
                'category'           => $category ?? 'General',
                'summary'            => $i['summary'],
                'timeline'           => 'Upon review',
                'company_service_id' => null,
                'reason'             => 'Category fallback — shown because AI-generated suggestions were unavailable.',
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{name: string, summary: string}> */
    private static function fallbackItemsFor(?string $category): array
    {
        if ($category) {
            $needle = self::categorySlug($category);

            foreach (self::categoryFallbackCatalog() as $key => $items) {
                if (str_contains($needle, $key) || str_contains($key, $needle)) {
                    return $items;
                }
            }
        }

        return collect(AiServiceCatalogService::fallbackCatalog(10))
            ->map(fn (array $s) => ['name' => $s['name'], 'summary' => $s['summary']])
            ->all();
    }

    private static function categorySlug(string $category): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $category) ?? '');
    }

    /**
     * A small set of common client-portal domains this product spec calls
     * out by name, keyed by categorySlug() so "E-commerce", "Point of Sale"
     * and "Retail" all resolve to the same list. Deliberately not exhaustive
     * — anything not recognized here falls through to the same general IT
     * list defaultServices() already uses (see fallbackItemsFor()).
     *
     * @return array<string, array<int, array{name: string, summary: string}>>
     */
    private static function categoryFallbackCatalog(): array
    {
        $finance = [
            ['name' => 'Payment Integration', 'summary' => 'Secure payment gateway integration for collecting and processing customer payments.'],
            ['name' => 'Risk Management', 'summary' => 'Tools to score, flag and manage financial risk across your portfolio.'],
            ['name' => 'Financial Reporting', 'summary' => 'Automated statements, ledgers and reports your team and auditors can rely on.'],
            ['name' => 'Compliance Management', 'summary' => 'Built-in checks to keep transactions and records aligned with regulatory requirements.'],
            ['name' => 'Automated Billing', 'summary' => 'Recurring invoices, reminders and installment schedules generated automatically.'],
            ['name' => 'Fraud Detection', 'summary' => 'Automated monitoring that flags suspicious transactions before they settle.'],
            ['name' => 'Loan & Installment Management', 'summary' => 'Track schedules, balances and repayments across every active plan.'],
            ['name' => 'Customer KYC Verification', 'summary' => 'Identity and document verification built into onboarding.'],
            ['name' => 'Multi-Currency Support', 'summary' => 'Accept and reconcile payments across multiple currencies.'],
            ['name' => 'Accounting Integration', 'summary' => 'Sync transactions directly with your existing accounting software.'],
        ];

        $ecommerce = [
            ['name' => 'Inventory Management', 'summary' => 'Real-time stock tracking across warehouses and sales channels.'],
            ['name' => 'Online Payment Integration', 'summary' => 'Secure checkout with the payment methods your customers actually use.'],
            ['name' => 'Customer Loyalty System', 'summary' => 'Points, rewards and repeat-purchase incentives built into checkout.'],
            ['name' => 'Sales Analytics', 'summary' => 'Dashboards that surface what is selling, where, and to whom.'],
            ['name' => 'Product Management', 'summary' => 'A catalogue that is easy to update — variants, pricing and media included.'],
            ['name' => 'Order Management', 'summary' => 'One place to track, fulfil and update every order end to end.'],
            ['name' => 'Barcode Integration', 'summary' => 'Scan-based stock counts and checkout for physical locations.'],
            ['name' => 'Accounting Integration', 'summary' => 'Sales and refunds synced straight into your books.'],
            ['name' => 'E-commerce Store Integration', 'summary' => 'Connect your storefront to the systems that run the rest of the business.'],
            ['name' => 'CRM Integration', 'summary' => 'Customer purchase history feeding directly into your CRM.'],
        ];

        $healthcare = [
            ['name' => 'Patient Records Management', 'summary' => 'A secure, searchable record for every patient encounter and history.'],
            ['name' => 'Appointment Scheduling', 'summary' => 'Online booking, reminders and reschedules without phone tag.'],
            ['name' => 'Telemedicine Integration', 'summary' => 'Video consultations built into the same portal patients already use.'],
            ['name' => 'Billing & Insurance Claims', 'summary' => 'Claims, co-pays and reimbursements tracked in one place.'],
            ['name' => 'HIPAA Compliance Tools', 'summary' => 'Access logging and safeguards aligned with healthcare data regulations.'],
            ['name' => 'Prescription Management', 'summary' => 'E-prescriptions and refill tracking tied to patient records.'],
        ];

        $realEstate = [
            ['name' => 'Property Listing Management', 'summary' => 'Listings, media and availability kept current across every channel.'],
            ['name' => 'Virtual Tours Integration', 'summary' => '360° walkthroughs embedded directly on each listing.'],
            ['name' => 'CRM Integration', 'summary' => 'Leads, viewings and follow-ups tracked from first enquiry to close.'],
            ['name' => 'Document & E-Signature Management', 'summary' => 'Contracts and disclosures signed and stored without printing anything.'],
            ['name' => 'Mortgage Calculator Integration', 'summary' => 'Instant affordability estimates on every listing page.'],
        ];

        $education = [
            ['name' => 'Learning Management System', 'summary' => 'Courses, assignments and grading in one platform for students and staff.'],
            ['name' => 'Student Information System', 'summary' => 'Enrollment, attendance and records kept in one authoritative place.'],
            ['name' => 'Online Assessment Tools', 'summary' => 'Quizzes and exams with automatic grading and plagiarism checks.'],
            ['name' => 'Video Conferencing Integration', 'summary' => 'Live classes built into the same portal students already log into.'],
            ['name' => 'Fee & Payment Management', 'summary' => 'Tuition invoicing, reminders and online payment collection.'],
        ];

        $logistics = [
            ['name' => 'Fleet Tracking', 'summary' => 'Live GPS tracking and route history across the whole fleet.'],
            ['name' => 'Route Optimization', 'summary' => 'Delivery routes planned automatically around traffic and priority.'],
            ['name' => 'Warehouse Management', 'summary' => 'Stock, bins and pick lists kept accurate across every location.'],
            ['name' => 'Shipment Tracking Integration', 'summary' => 'Real-time status updates shared straight with your customers.'],
            ['name' => 'Inventory Management', 'summary' => 'Real-time stock tracking across warehouses and sales channels.'],
        ];

        $hospitality = [
            ['name' => 'Online Booking System', 'summary' => 'Real-time availability and reservations across every channel.'],
            ['name' => 'Guest Loyalty Program', 'summary' => 'Points and perks that bring guests back for their next stay.'],
            ['name' => 'Channel Manager Integration', 'summary' => 'Rates and availability synced across every listing site at once.'],
            ['name' => 'Point of Sale Integration', 'summary' => 'Restaurant, bar and front-desk sales tied into one system.'],
            ['name' => 'Review & Reputation Management', 'summary' => 'Guest reviews monitored and responded to from one dashboard.'],
        ];

        return [
            'finance'            => $finance,
            'financialservices'  => $finance,
            'financial'          => $finance,
            'installment'        => $finance,
            'banking'            => $finance,
            'fintech'            => $finance,
            'ecommerce'          => $ecommerce,
            'retail'             => $ecommerce,
            'pointofsale'        => $ecommerce,
            'pos'                => $ecommerce,
            'shopping'           => $ecommerce,
            'healthcare'         => $healthcare,
            'medical'            => $healthcare,
            'health'             => $healthcare,
            'realestate'         => $realEstate,
            'property'           => $realEstate,
            'education'          => $education,
            'elearning'          => $education,
            'learning'           => $education,
            'logistics'          => $logistics,
            'supplychain'        => $logistics,
            'shipping'           => $logistics,
            'hospitality'        => $hospitality,
            'travel'             => $hospitality,
            'hotel'              => $hospitality,
        ];
    }

    /**
     * The one place a project's free-form name/description turns into a
     * clean domain label — "book management" -> "Publishing", "storefront
     * revamp" -> "E-commerce", etc. — generically, never hardcoded to any
     * specific domain.
     *
     * @return array{primary_category: ?string, keywords: string[]}
     */
    private static function normalizeContext(array $context): array
    {
        // A tagged project or a hit against the built-in IT-agency verticals
        // is an exact, trustworthy signal — skip the AI call entirely.
        if ($context['category_hint']) {
            return ['primary_category' => $context['category_hint'], 'keywords' => []];
        }

        if ($context['project_text'] === '') {
            return ['primary_category' => null, 'keywords' => []];
        }

        $ai = self::aiNormalizeContext($context['project_text']);
        if ($ai) {
            return $ai;
        }

        // AI unavailable — no clean category label, but ranking can still use
        // the project's own significant words for overlap scoring below.
        return ['primary_category' => null, 'keywords' => self::significantWords($context['project_text'])];
    }

    /**
     * @return array{primary_category: string, keywords: string[]}|null
     */
    private static function aiNormalizeContext(string $projectText): ?array
    {
        $prompt = <<<PROMPT
Classify the business/industry domain of this client project, on a client portal for a services agency (software,
creative, marketing, or similar) — this project describes what the CLIENT is trying to achieve for themselves or
their own business, not an internal enterprise system the agency might build for some unrelated third party.

Project: "{$projectText}"

When the wording is short or ambiguous, prefer the interpretation where the client is the direct beneficiary/creator
of the subject matter (e.g. "book management" most likely means an author/publisher managing their own book's
publication — Publishing — rather than a library's internal circulation software) unless the text clearly names a
specific enterprise system (e.g. explicitly mentions "library system", "inventory system", "ERP", "CRM").

Return ONLY valid JSON, no markdown, no prose. Shape:
{ "primary_category": "a short, specific domain label such as Publishing, E-commerce, Healthcare, Real Estate, Web Development, Event Planning, etc.", "keywords": ["3 to 6 short keywords describing this domain"] }
PROMPT;

        $response = AiRouter::complete([['role' => 'user', 'content' => $prompt]], 8, 300, '[service-recs-context]', []);

        if ($response === null) {
            return null;
        }

        $decoded = self::decodeJsonObject((string) ($response['content'] ?? ''));

        if (!is_array($decoded) || empty($decoded['primary_category'])) {
            return null;
        }

        return [
            'primary_category' => trim((string) $decoded['primary_category']),
            'keywords'         => is_array($decoded['keywords'] ?? null) ? array_map('strval', $decoded['keywords']) : [],
        ];
    }

    /**
     * The company HAS its own service catalogue — every suggestion below is
     * constrained to that real, enabled list. AI ranks it when available;
     * a keyword/category overlap stands in when it isn't. Either way, no id
     * outside the company's own catalogue can ever be returned, and nothing
     * below MIN_RELEVANCE is shown.
     */
    private static function rankCatalog(array $context, array $normalized, int $count, ?string $focusContext): array
    {
        $candidates = $context['available']->map(fn (CompanyService $s) => [
            'id'          => $s->id,
            'name'        => $s->presentation()['name'],
            'description' => $s->presentation()['short_description'] ?? '',
            'group'       => $s->presentation()['group'],
        ])->values()->all();

        $ranked = self::aiRankCatalog($context, $normalized, $candidates, $count, $focusContext);

        if (!$ranked) {
            $ranked = self::tagMatchRankCatalog($context, $normalized, $candidates, $count);
        }

        $byId = $context['available']->keyBy('id');

        return collect($ranked)
            ->map(function (array $r) use ($byId) {
                /** @var CompanyService|null $service */
                $service = $byId->get($r['id']);
                if (!$service) {
                    return null;
                }

                $presentation = $service->presentation();

                return [
                    'key'                => $presentation['slug'] ?? ('company_service_' . $presentation['id']),
                    'name'               => $presentation['name'],
                    'category'           => $presentation['group'],
                    'summary'            => $presentation['short_description'] ?: 'A service offered by your project team.',
                    'timeline'           => 'Upon review',
                    'company_service_id' => $presentation['id'],
                    'reason'             => $r['reason'] ?? '',
                ];
            })
            ->filter()
            ->take($count)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id: int, name: string, description: string, group: string}>  $candidates
     * @return array<int, array{id: int, reason: string}>
     */
    private static function aiRankCatalog(array $context, array $normalized, array $candidates, int $count, ?string $focusContext): array
    {
        if (!$candidates) {
            return [];
        }

        $catalogJson = json_encode(array_map(
            fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'group' => $c['group']],
            $candidates
        ));

        $domain = $normalized['primary_category'] ?? 'unclear — infer it from the project below';
        $keywordsText = $normalized['keywords'] ? implode(', ', $normalized['keywords']) : 'none identified';
        $projectText = $context['project_text'] !== '' ? $context['project_text'] : 'No project details available yet.';
        $ownedText = $context['owned_names'] !== [] ? implode(', ', $context['owned_names']) : 'none yet';
        $focusSection = $focusContext !== null && trim($focusContext) !== '' ? "\nAdditional context: {$focusContext}\n" : '';

        $prompt = <<<PROMPT
You are ranking which of a company's OWN services to recommend to one of its clients on a client portal dashboard.

Client's business/industry domain: {$domain}
Domain keywords: {$keywordsText}
Client's current/most relevant project: {$projectText}

Services already purchased or already offered to this client FOR THIS PROJECT (never recommend these again):
{$ownedText}
{$focusSection}
Available services this company can actually deliver — choose ONLY from this list, referencing each by its "id":
{$catalogJson}

Return ONLY valid JSON, no markdown, no prose. Shape:
[
  { "service_id": 123, "relevance_score": 0.0, "reason": "short internal reason, not shown to the client" }
]

Rules:
- Only include ids that appear in the available services list above. Never invent an id or a service name.
- Judge relevance using the FULL semantic meaning of each service (its name AND description AND group together)
  against the FULL project context (domain, keywords, project text) — never on a single overlapping word.
- Apply this test before scoring high: would a company OUTSIDE the "{$domain}" domain plausibly offer this exact
  service too? If yes — it is a generic business/technology function (e.g. data/metadata management, workflow
  automation, CRM, inventory, security auditing, generic analytics dashboards, staff scheduling, data migration)
  dressed in domain-adjacent wording — score it LOW (below 0.5) even if a word superficially overlaps. Only score
  high (0.75+) a service that domain professionals would specifically seek out, or that would make little sense
  for a company outside this domain.
- Do not recommend something already inherently part of delivering the project itself (i.e. already covered by the
  project text above) — only complementary or follow-on services beyond the project's own original scope.
- Exclude services the client already has or has already been offered for this project.
- Return at most {$count} items. Fewer is fine, and returning none at all is correct if nothing is a strong fit.
PROMPT;

        $response = AiRouter::complete(
            [['role' => 'user', 'content' => $prompt]],
            self::TIMEOUT_SECONDS,
            self::MAX_TOKENS,
            '[service-recs]',
            ['client_id' => $context['client_id']]
        );

        if ($response === null) {
            return [];
        }

        $decoded = self::decodeJsonArray((string) ($response['content'] ?? ''));
        $validIds = collect($candidates)->pluck('id')->all();
        $byId = collect($candidates)->keyBy('id');

        $scored = collect($decoded)
            ->filter(fn ($item) => is_array($item) && in_array((int) ($item['service_id'] ?? 0), $validIds, true))
            ->map(fn (array $item) => [
                'id'     => (int) $item['service_id'],
                'score'  => (float) ($item['relevance_score'] ?? 0),
                'reason' => trim((string) ($item['reason'] ?? '')),
            ])
            ->unique('id');

        self::logScoring($context['client_id'], $scored->map(fn (array $s) => $s + [
            'name' => $byId->get($s['id'])['name'] ?? null,
        ])->all());

        return $scored
            ->filter(fn (array $item) => $item['score'] >= self::MIN_RELEVANCE)
            ->sortByDesc('score')
            ->take($count)
            ->map(fn (array $item) => ['id' => $item['id'], 'reason' => $item['reason']])
            ->values()
            ->all();
    }

    /**
     * Explainable-ranking debug trail (product spec requirement) — every
     * candidate this generation considered, its score, its reason, and
     * whether it made the cut, so a "why is X excluded / why isn't Y here"
     * question can be answered by reading the log rather than guessing.
     */
    private static function logScoring(int $clientId, array $scored): void
    {
        Log::info('[service-recs-scoring] candidates evaluated', [
            'client_id'  => $clientId,
            'candidates' => collect($scored)->map(fn (array $s) => [
                'name'    => $s['name'] ?? null,
                'score'   => $s['score'],
                'reason'  => $s['reason'],
                'result'  => $s['score'] >= self::MIN_RELEVANCE ? 'included' : 'excluded',
            ])->sortByDesc('score')->values()->all(),
        ]);
    }

    /**
     * Non-AI fallback for a company with its own real catalogue: a plain word
     * overlap between the project's own text (plus normalizeContext()'s
     * detected domain keywords) and each service's name/description/group,
     * plus a bonus when a service's group matches the detected domain.
     * Nothing below MIN_RELEVANCE is returned — if nothing overlaps at all,
     * this returns empty rather than the company's services in arbitrary
     * order, since an unranked list is exactly the "filler" the product spec
     * rules out.
     */
    private static function tagMatchRankCatalog(array $context, array $normalized, array $candidates, int $count): array
    {
        $projectWords = array_values(array_unique(array_merge(
            self::significantWords($context['project_text']),
            $normalized['keywords'] ?? []
        )));
        $categoryHint = $normalized['primary_category'] ?? $context['category_hint'];

        $scored = collect($candidates)->map(function (array $c) use ($projectWords, $categoryHint) {
            $serviceWords = self::significantWords($c['name'] . ' ' . $c['description'] . ' ' . $c['group']);
            $overlap = count(array_intersect($projectWords, $serviceWords));
            $categoryMatch = $categoryHint !== null && (
                stripos($c['group'], $categoryHint) !== false || stripos($categoryHint, $c['group']) !== false
            );

            // A clean category match is the strong signal; raw word overlap
            // alone climbs slowly on purpose — a single incidental shared
            // word must never be enough to clear MIN_RELEVANCE by itself.
            $score = min(1.0, ($categoryMatch ? 0.8 : 0.0) + $overlap * 0.1);

            return ['id' => $c['id'], 'score' => $score, 'reason' => 'Keyword/category match (AI unavailable)', 'name' => $c['name']];
        });

        self::logScoring($context['client_id'], $scored->all());

        return $scored
            ->filter(fn (array $s) => $s['score'] >= self::MIN_RELEVANCE)
            ->sortByDesc('score')
            ->take($count)
            ->values()
            ->all();
    }

    /**
     * Generates AI suggestions steered by the normalized domain — called
     * either as the ONLY source (the company has configured no services of
     * its own, nothing real to constrain against) or as a TOP-UP (the real
     * catalogue above didn't reach $count on its own; $excludeNames then
     * carries those already-chosen real services so nothing repeats). Either
     * way every item must both (a) name the normalized domain (or a specific
     * facet of it) as its own category, never an unrelated generic business
     * function, and (b) clear MIN_RELEVANCE. A "book management" project
     * reads as Publishing and only gets publishing-flavoured suggestions —
     * never inventory/CRM/security/mobile filler — for exactly the same
     * reason a website project only gets web-flavoured ones: both are driven
     * by the one normalized domain, not by a per-domain hardcoded list.
     */
    private static function rankFreeform(array $context, array $normalized, int $count, ?string $focusContext, array $excludeNames = []): array
    {
        if ($count <= 0) {
            return [];
        }

        $domain = $normalized['primary_category'] ?? 'unclear — infer it from the project below';
        $keywordsText = $normalized['keywords'] ? implode(', ', $normalized['keywords']) : 'none identified';
        $projectText = $context['project_text'] !== '' ? $context['project_text'] : 'No project details available yet.';
        // Already-owned AND already-shown-elsewhere-in-this-list (the
        // company's own real catalogue services, when generate() is topping
        // that list up to $count) share one "don't repeat" instruction —
        // both are, from the AI's point of view, services the client is
        // already being offered and must not see twice.
        $namesToAvoid = array_values(array_unique(array_merge($context['owned_names'], $excludeNames)));
        $ownedText = $namesToAvoid !== [] ? implode(', ', $namesToAvoid) : 'none yet';
        $focusSection = $focusContext !== null && trim($focusContext) !== '' ? "\nAdditional context: {$focusContext}\n" : '';

        $prompt = <<<PROMPT
A client portal needs service suggestions for a client whose project belongs to this domain: {$domain}

Project: {$projectText}
Domain keywords: {$keywordsText}
Services already purchased or already offered to this client for THIS project (never repeat, never suggest a close
duplicate): {$ownedText}
{$focusSection}
Generate a plausible, realistic set of ADDITIONAL services a company serving THIS domain would offer this client
next.

Return ONLY valid JSON, no markdown, no prose. Shape:
[
  { "name": "Service name", "category": "a specific facet of the {$domain} domain", "summary": "one clear client-facing sentence", "timeline": "e.g. 1-3 weeks", "relevance_score": 0.0 }
]

Rules:
- The domain "{$domain}" is already a confident classification — it is a strong signal on its own. If the project
  text above is thin, generic, or a placeholder, do NOT treat that as a reason to return nothing: generate the
  standard, well-known services a company serving the {$domain} domain commonly offers. Only return an empty list
  when the domain itself is unclear (shown above as "unclear — infer it from the project below").
- Every service must be clearly and specifically relevant to the {$domain} domain, judged by its FULL name +
  summary + category together, not a single keyword.
- Apply this test before scoring high: would a company OUTSIDE the "{$domain}" domain plausibly offer this exact
  service too? If yes — it is a generic business/technology function (data/metadata management, workflow
  automation, CRM, inventory sync, security audits, generic analytics dashboards, staff scheduling, data migration,
  generic software development, etc.) dressed in domain-adjacent wording — do not include it, or if included score
  it LOW (below 0.5). Only score high (0.75+) a service that domain professionals would specifically seek out.
- Each service's "category" must read as a facet of {$domain} (for a Publishing project: Marketing, Design,
  Distribution, Editorial — never Sales, Inventory, Security, or Operations).
- Do not suggest something already inherently part of delivering the project itself (i.e. already covered by the
  project text above) — only complementary or follow-on services beyond the project's own original scope.
- Exclude anything the client already has or has already been offered for this project.
- Return at most {$count} items.
PROMPT;

        // Free-tier models are occasionally flaky in a way that isn't
        // "genuinely nothing fits" — the exact same prompt can return a full,
        // relevant list on one call and an empty or all-excluded one on the
        // next, and rarely even twice in a row. Up to 3 attempts before
        // accepting "nothing qualifies" as final costs extra calls only on
        // that rare path, never on a normal successful response (which stops
        // at attempt 1), and is judged on the POST-THRESHOLD result (not
        // just "did it return JSON") so a response that parsed fine but
        // scored everything too low also gets another chance.
        $survivors = collect();
        for ($attempt = 0; $attempt < 3 && $survivors->isEmpty(); $attempt++) {
            $response = AiRouter::complete(
                [['role' => 'user', 'content' => $prompt]],
                self::TIMEOUT_SECONDS,
                self::MAX_TOKENS,
                '[service-recs]',
                ['client_id' => $context['client_id']]
            );

            if ($response === null) {
                break;
            }

            $decoded = self::decodeJsonArray((string) ($response['content'] ?? ''));

            $scored = collect($decoded)
                ->filter(fn ($item) => is_array($item) && trim((string) ($item['name'] ?? '')) !== '')
                ->map(function (array $item) use ($normalized) {
                    return [
                        'key'                => self::slugify((string) $item['name']),
                        'name'               => trim((string) $item['name']),
                        'category'           => trim((string) ($item['category'] ?: ($normalized['primary_category'] ?? 'General'))),
                        'summary'            => trim((string) ($item['summary'] ?? '')),
                        'timeline'           => trim((string) ($item['timeline'] ?? 'Upon review')),
                        // Never backed by a real catalogue row — that's the
                        // whole reason this generation path exists.
                        'company_service_id' => null,
                        // A model that structurally complies but forgets this
                        // one field shouldn't have its entire (otherwise
                        // well-steered) response discarded — default just
                        // above the bar rather than to 0, which would erase
                        // everything.
                        'score'    => isset($item['relevance_score']) ? (float) $item['relevance_score'] : 0.8,
                        'reason'   => 'AI-generated for the ' . ($normalized['primary_category'] ?? 'detected') . ' domain',
                    ];
                })
                ->filter(fn (array $i) => $i['summary'] !== '')
                ->unique('key');

            self::logScoring($context['client_id'], $scored->all());

            $survivors = $scored->filter(fn (array $i) => $i['score'] >= self::MIN_RELEVANCE);
        }

        return $survivors
            ->sortByDesc('score')
            ->take($count)
            ->map(fn (array $i) => Arr::except($i, ['score']))
            ->values()
            ->all();
    }

    private static function slugify(string $name): string
    {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name) ?? '');

        return trim($key, '_') ?: 'service_' . substr(md5($name), 0, 8);
    }

    private static function decodeJsonArray(string $text): array
    {
        $json = trim($text);

        if (!str_starts_with($json, '[') && preg_match('/\[[\s\S]*\]/', $json, $match)) {
            $json = $match[0];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeJsonObject(string $text): ?array
    {
        $json = trim($text);

        if (!str_starts_with($json, '{') && preg_match('/\{[\s\S]*\}/', $json, $match)) {
            $json = $match[0];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return string[] */
    private static function significantWords(string $text): array
    {
        static $stop = ['the', 'a', 'an', 'and', 'or', 'for', 'of', 'to', 'in', 'on', 'with', 'your', 'our', 'is', 'are', 'this', 'that', 'it', 'you', 'we'];

        $words = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) > 2 && !in_array($w, $stop, true))));
    }
}
