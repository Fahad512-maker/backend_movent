<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyService;
use App\Models\Project;
use App\Models\ServiceRequest;
use App\Models\SystemAuditLog;
use App\Services\ServiceCatalog;
use App\Support\PublicFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

// The Company Admin's own "Services" screen — the IT services their clients
// are offered in the Client Portal's "Grow Your Business With Us" section.
//
// Company Admin only, deliberately: what a company sells is the owner's call,
// not a delegated one, so there is no staff counterpart to this controller.
//
// index() returns the CATALOGUE merged with this company's rows rather than
// just the rows: the 37 built-in services live in code (ServiceCatalog) and a
// row only exists once the admin has decided something about that service, so
// a fresh company has zero rows and still has to see 37 things to switch on.
class CompanyServiceController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()
    {
        return auth('admin')->user();
    }

    // Every company this admin owns — the same scoping helper the rest of the
    // admin controllers define (see Api\Admin\BrandController).
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function companyId(Request $request): int
    {
        $owned = $this->companyIds();

        $requested = (int) $request->input('company_id');
        if ($requested && in_array($requested, $owned, true)) {
            return $requested;
        }

        return (int) ($this->activeCompanyIds()[0] ?? $owned[0] ?? 0);
    }

    private function logAudit(Request $request, int $companyId, string $action, ?int $entityId, array $new = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => null,
            'action'      => $action,
            'module_key'  => 'client_portal',
            'entity_type' => 'CompanyService',
            'entity_id'   => $entityId,
            'new_values'  => $new ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    // $projectsByService: Project rows (grouped by company_service_id) that
    // delivered this service — the source of "which clients use this",
    // since a CompanyService itself carries no client. Omitted (create/
    // update/toggle responses) just shows zero; the list re-fetches the real
    // count on its next load() anyway.
    private function format(CompanyService $service, ?Collection $projectsByService = null): array
    {
        $catalog = $service->catalogEntry();

        $clients = ($projectsByService?->get($service->id) ?? collect())
            ->filter(fn (Project $p) => $p->client)
            ->unique('client_id')
            ->map(fn (Project $p) => [
                'client_id'    => $p->client->id,
                'client_name'  => $p->client->name,
                'company_id'   => $p->company?->id,
                'company_name' => $p->company?->name,
            ])
            ->values();

        return [
            'id'                => $service->id,
            'slug'              => $service->slug,
            'name'              => $service->name,
            'short_description' => $service->short_description,
            'icon_url'          => PublicFile::url($service->icon_path),
            'icon_emoji'        => $catalog['icon'] ?? '💼',
            'group'             => $catalog['group'] ?? 'Custom',
            'starting_price'    => $service->starting_price !== null ? (float) $service->starting_price : null,
            'is_enabled'        => (bool) $service->is_enabled,
            'is_featured'       => (bool) $service->is_featured,
            'display_order'     => (int) $service->display_order,
            'is_custom'         => $service->slug === null,
            // What the catalogue suggests, so the form can offer "reset to the
            // standard wording" rather than the admin retyping it.
            'catalog_default'   => $catalog ? [
                'name'        => $catalog['name'],
                'description' => $catalog['description'],
            ] : null,
            'clients'           => $clients,
            'clients_count'     => $clients->count(),
        ];
    }

    // GET /admin/services?company_id=
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) {
            return ApiResponse::error('No company found', 404);
        }

        $rows = CompanyService::where('company_id', $companyId)->forDisplay()->get();
        $bySlug = $rows->whereNotNull('slug')->keyBy('slug');

        // Which clients (and their company) actually use each service —
        // "used" means a Project delivering it, not just a request/inquiry.
        // notDraft(): a draft/unpaid project isn't real work yet (see
        // Project::PRE_ACTIVATION_STATUSES), so it doesn't count as usage.
        $projectsByService = Project::whereIn('company_service_id', $rows->pluck('id')->all())
            ->notDraft()
            ->whereNotNull('client_id')
            ->with(['client:id,name', 'company:id,name'])
            ->get()
            ->groupBy('company_service_id');

        // Catalogue services the admin has never touched: returned as
        // unsaved, disabled placeholders carrying the catalogue's own wording,
        // so the screen is one list of 37+ rather than "your 3" and a separate
        // "add from catalogue" dialog. Never has clients — nothing can be
        // tagged with a service that has no row yet.
        $untouched = collect(ServiceCatalog::all())
            ->reject(fn ($entry, string $slug) => $bySlug->has($slug))
            ->map(fn (array $entry, string $slug) => [
                'id'                => null,
                'slug'              => $slug,
                'name'              => $entry['name'],
                'short_description' => $entry['description'],
                'icon_url'          => null,
                'icon_emoji'        => $entry['icon'],
                'group'             => $entry['group'],
                'starting_price'    => null,
                'is_enabled'        => false,
                'is_featured'       => false,
                'display_order'     => 0,
                'is_custom'         => false,
                'catalog_default'   => ['name' => $entry['name'], 'description' => $entry['description']],
                'clients'           => [],
                'clients_count'     => 0,
            ])
            ->values();

        return ApiResponse::success([
            'services'      => $rows->map(fn (CompanyService $s) => $this->format($s, $projectsByService))->values()
                ->concat($untouched)->values(),
            'enabled_count' => $rows->where('is_enabled', true)->count(),
            'currency'      => Company::find($companyId)?->currency ?: 'USD',
            // The section only appears in a portal at all if the company has
            // the Client Portal module — worth saying so here rather than
            // leaving the admin wondering why clients see nothing.
            'portal_module_active' => Company::find($companyId)?->modules()
                ->where('module_key', 'client_portal')->where('is_enabled', true)->exists() ?? false,
        ]);
    }

    // POST /admin/services — a custom service, or the first save of a
    // catalogue one (the screen posts the slug it was showing).
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) {
            return ApiResponse::error('No company found', 404);
        }

        $validated = $this->validatePayload($request, required: true);

        $slug = $validated['slug'] ?? null;
        if ($slug !== null && !ServiceCatalog::has($slug)) {
            return ApiResponse::error('Unknown service.', 422);
        }

        // A catalogue service already saved for this company is an update, not
        // a second row — the unique index would reject it anyway, and this
        // makes the screen's "enable" click work whichever state it was in.
        if ($slug !== null) {
            $existing = CompanyService::withTrashed()
                ->where('company_id', $companyId)->where('slug', $slug)->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                return $this->applyUpdate($request, $existing, $validated);
            }
        }

        $service = CompanyService::create([
            'company_id'          => $companyId,
            'slug'                => $slug,
            'name'                => $validated['name'],
            'short_description'   => $validated['short_description'] ?? null,
            'starting_price'      => $validated['starting_price'] ?? null,
            'is_enabled'          => $validated['is_enabled'] ?? false,
            'is_featured'         => $validated['is_featured'] ?? false,
            'display_order'       => $validated['display_order'] ?? 0,
            'created_by_admin_id' => $this->admin()->id,
        ]);

        $this->storeIcon($request, $service);

        $this->logAudit($request, $companyId, 'service.created', $service->id, ['name' => $service->name]);

        return ApiResponse::success($this->format($service->fresh()), 'Service saved', 201);
    }

    // POST /admin/services/{id} — POST, not PUT: the icon is multipart and PHP
    // does not parse multipart bodies on PUT. Same shape as brands.
    public function update(Request $request, int $id): JsonResponse
    {
        $service = CompanyService::whereIn('company_id', $this->companyIds())->find($id);
        if (!$service) {
            return ApiResponse::error('Service not found', 404);
        }

        return $this->applyUpdate($request, $service, $this->validatePayload($request, required: false));
    }

    private function applyUpdate(Request $request, CompanyService $service, array $validated): JsonResponse
    {
        // slug is identity, never editable — changing it would silently move a
        // service onto a different set of recommendation rules.
        unset($validated['slug']);

        $service->update($validated);
        $this->storeIcon($request, $service);

        $this->logAudit($request, $service->company_id, 'service.updated', $service->id, $validated);

        return ApiResponse::success($this->format($service->fresh()), 'Service saved');
    }

    private function validatePayload(Request $request, bool $required): array
    {
        return $request->validate([
            'slug'              => ['nullable', 'string', 'max:80'],
            'name'              => [$required ? 'required' : 'sometimes', 'string', 'max:150'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'starting_price'    => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_enabled'        => ['sometimes', 'boolean'],
            'is_featured'       => ['sometimes', 'boolean'],
            'display_order'     => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'icon'              => ['nullable', 'file', 'mimetypes:image/*', 'max:2048'],
        ], [
            'icon.mimetypes' => 'The icon must be an image file.',
            'icon.max'       => 'The icon must be 2 MB or smaller.',
        ]);
    }

    private function storeIcon(Request $request, CompanyService $service): void
    {
        if (!$request->hasFile('icon')) {
            return;
        }

        if ($service->icon_path && Storage::disk('public')->exists($service->icon_path)) {
            Storage::disk('public')->delete($service->icon_path);
        }

        $service->update([
            'icon_path' => $request->file('icon')->store("companies/{$service->company_id}/services", 'public'),
        ]);
    }

    // PATCH /admin/services/{id}/toggle-status
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $service = CompanyService::whereIn('company_id', $this->companyIds())->find($id);
        if (!$service) {
            return ApiResponse::error('Service not found', 404);
        }

        $service->update(['is_enabled' => !$service->is_enabled]);

        $this->logAudit(
            $request,
            $service->company_id,
            $service->is_enabled ? 'service.enabled' : 'service.disabled',
            $service->id
        );

        return ApiResponse::success($this->format($service), $service->is_enabled ? 'Service enabled' : 'Service disabled');
    }

    // PUT /admin/services/reorder — the whole list in one call, so dragging a
    // card doesn't fire one request per row it shifted past.
    public function reorder(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $owned = CompanyService::where('company_id', $companyId)
            ->whereIn('id', $validated['order'])->pluck('id')->all();

        foreach ($validated['order'] as $position => $serviceId) {
            if (!in_array((int) $serviceId, $owned, true)) {
                continue;
            }
            CompanyService::where('id', $serviceId)->update(['display_order' => $position]);
        }

        return ApiResponse::success(null, 'Order saved');
    }

    // DELETE /admin/services/{id}
    //
    // Soft delete: projects tagged with this service and requests raised
    // against it both keep resolving to a row that still exists, so their
    // history stays readable (both relations use withTrashed()).
    public function destroy(Request $request, int $id): JsonResponse
    {
        $service = CompanyService::whereIn('company_id', $this->companyIds())->find($id);
        if (!$service) {
            return ApiResponse::error('Service not found', 404);
        }

        $companyId = $service->company_id;
        $service->delete();

        $this->logAudit($request, $companyId, 'service.deleted', $id, ['name' => $service->name]);

        return ApiResponse::success(null, 'Service removed');
    }

    // GET /admin/service-requests — what clients have asked for. Merges every
    // company this admin owns into one list (multiple companies otherwise had
    // no way to tell which client's request belonged to which of them), with
    // an optional company_id to narrow it back down to just one.
    public function requests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'     => ['nullable', 'string', 'in:' . implode(',', ServiceRequest::STATUSES)],
            'company_id' => ['nullable', 'integer'],
        ]);

        $owned = $this->companyIds();
        $requestedCompanyId = (int) ($validated['company_id'] ?? 0);
        $filterCompanyId = ($requestedCompanyId && in_array($requestedCompanyId, $owned, true)) ? $requestedCompanyId : null;

        $rows = ServiceRequest::whereIn('company_id', $owned)
            ->when($filterCompanyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['client:id,name,email', 'company:id,name', 'lead:id,name'])
            ->latest()
            ->limit(200)
            ->get();

        return ApiResponse::success([
            'requests' => $rows->map(fn (ServiceRequest $r) => [
                'id'             => $r->id,
                'client'         => $r->client ? ['id' => $r->client->id, 'name' => $r->client->name, 'email' => $r->client->email] : null,
                'company'        => $r->company ? ['id' => $r->company->id, 'name' => $r->company->name] : null,
                'service_name'   => $r->service_name,
                'intent'         => $r->intent,
                'intent_label'   => $r->intentLabel(),
                'message'        => $r->message,
                'preferred_date' => $r->preferred_date?->toDateString(),
                'status'         => $r->status,
                'lead_id'        => $r->lead_id,
                'created_at'     => $r->created_at,
            ]),
            // Scoped the same as $rows above — a request from Company B must
            // never pad the "new" badge while Company A is the one in view.
            'new_count' => ServiceRequest::whereIn('company_id', $owned)
                ->when($filterCompanyId, fn ($q, $id) => $q->where('company_id', $id))
                ->where('status', 'new')
                ->count(),
        ]);
    }

    // PATCH /admin/service-requests/{id}
    public function updateRequest(Request $request, int $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::whereIn('company_id', $this->companyIds())->find($id);
        if (!$serviceRequest) {
            return ApiResponse::error('Request not found', 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', ServiceRequest::STATUSES)],
        ]);

        $serviceRequest->update($validated);

        return ApiResponse::success(null, 'Request updated');
    }
}
