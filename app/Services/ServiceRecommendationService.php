<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CompanyService;
use App\Models\Invoice;
use App\Models\Project;

/**
 * What to put in front of a client on their portal dashboard.
 *
 * Three lists, and they never repeat each other:
 *
 *   recommended  Worked out from what this client already has — a website
 *                leads to SEO, maintenance and hosting; a mobile app leads to
 *                bug fixing and QA. This is the list that earns the section.
 *   featured     The company's own picks (is_featured), for a client we can
 *                infer nothing about yet, or below the recommendations.
 *   all          Everything else the company has enabled, so a client who
 *                wants something we didn't think of can still find it.
 *
 * How "what the client already has" is decided, in order of confidence:
 *
 *   1. Projects tagged with a service (projects.company_service_id). Exact,
 *      and the reason that column exists.
 *   2. The NAME of any untagged project, matched against the catalogue's
 *      keywords. Every project that predates the tag is in this bucket, so
 *      without it existing clients would get no recommendations at all.
 *   3. Invoice line descriptions, same keyword matching — this is what catches
 *      one-off work that was billed but never became a project (a logo, a
 *      speed audit).
 *
 * Only ever suggests services the company has actually ENABLED. A rule can
 * point at SEO all it likes; if this company doesn't sell SEO, the client is
 * never shown it.
 */
class ServiceRecommendationService
{
    /** Keeps the dashboard section a readable size rather than a catalogue. */
    public const MAX_RECOMMENDED = 6;

    /**
     * @param  Client[]|\Illuminate\Support\Collection  $clients  Every client
     *         record this portal login can see — one person can hold more than
     *         one, and what they bought under either is still theirs.
     * @return array{recommended: array[], featured: array[], all: array[], based_on: string[]}
     */
    public static function for($clients, int $companyId): array
    {
        $clientIds = collect($clients)->pluck('id')->all();

        $enabled = CompanyService::where('company_id', $companyId)
            ->enabled()
            ->forDisplay()
            ->get();

        if ($enabled->isEmpty()) {
            return ['recommended' => [], 'featured' => [], 'all' => [], 'based_on' => []];
        }

        $ownedSlugs = self::ownedSlugs($clientIds, $companyId);

        // Catalogue slug → the company's own row for it. Custom services (null
        // slug) are deliberately absent: the rules can't reason about them.
        $bySlug = $enabled->filter(fn (CompanyService $s) => $s->slug !== null)->keyBy('slug');

        $recommended = collect(ServiceCatalog::recommendationsFor($ownedSlugs))
            ->map(fn (string $slug) => $bySlug->get($slug))
            ->filter()
            ->take(self::MAX_RECOMMENDED)
            ->values();

        $recommendedIds = $recommended->pluck('id')->all();

        // Featured excludes anything already recommended — the same card twice
        // on one screen looks like a bug, and the recommendation is the
        // stronger reason to show it.
        $featured = $enabled
            ->where('is_featured', true)
            ->whereNotIn('id', $recommendedIds)
            ->values();

        $shownIds = array_merge($recommendedIds, $featured->pluck('id')->all());

        // ->values() on every one of these, and it is not cosmetic: whereNotIn
        // and where() PRESERVE the collection's keys, so filtering items out
        // of the middle leaves gaps ([0,1,2,4,7]) — and a PHP array with
        // non-sequential keys serialises to a JSON *object*, not an array.
        // The client portal then gets {"0":{…},"1":{…}} and .map() on it
        // throws "shown.map is not a function". $recommended and $featured
        // are already re-indexed above; 'all' is filtered right here, so it
        // has to be re-indexed right here too.
        return [
            'recommended' => $recommended->map(fn (CompanyService $s) => $s->presentation())->values()->all(),
            'featured'    => $featured->map(fn (CompanyService $s) => $s->presentation())->values()->all(),
            'all'         => $enabled->whereNotIn('id', $shownIds)
                ->map(fn (CompanyService $s) => $s->presentation())->values()->all(),
            // Why these were suggested, so the portal can say "because you
            // already have a website with us" instead of asking the client to
            // take it on trust.
            'based_on'    => collect($ownedSlugs)
                ->map(fn (string $slug) => ServiceCatalog::get($slug)['name'] ?? null)
                ->filter()->values()->all(),
        ];
    }

    /**
     * The catalogue slugs this client already has, from the three sources
     * described above.
     *
     * @return string[]
     */
    private static function ownedSlugs(array $clientIds, int $companyId): array
    {
        if (!$clientIds) {
            return [];
        }

        $slugs = [];

        // 1 + 2. Projects: the tag where present, the name where not.
        $projects = Project::whereIn('client_id', $clientIds)
            ->where('company_id', $companyId)
            // A cancelled project is not something the client "has" — offering
            // add-ons for work that never happened reads as not paying
            // attention.
            ->where('status', '!=', 'cancelled')
            ->with('companyService:id,slug')
            ->get(['id', 'name', 'company_service_id', 'status', 'client_id', 'company_id']);

        foreach ($projects as $project) {
            $tagged = $project->companyService?->slug;

            if ($tagged) {
                $slugs[] = $tagged;
                continue;
            }

            foreach (ServiceCatalog::detectSlugs($project->name) as $slug) {
                $slugs[] = $slug;
            }
        }

        // 3. Billed work that never became a project. Only invoices that were
        // actually issued — a draft is an intention, not something the client
        // has.
        $descriptions = Invoice::whereIn('client_id', $clientIds)
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('items:id,invoice_id,description')
            ->get(['id', 'client_id', 'company_id', 'status'])
            ->flatMap(fn (Invoice $i) => $i->items->pluck('description'))
            ->filter()
            ->implode(' . ');

        if ($descriptions !== '') {
            foreach (ServiceCatalog::detectSlugs($descriptions) as $slug) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }
}
