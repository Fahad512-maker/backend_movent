<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\BrandController as AdminBrandController;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyUserAssignment;
use App\Models\Invoice;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Rules\ValidPhoneNumber;
use App\Support\PermissionDebug;
use App\Support\PublicFile;
use App\Support\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// Staff counterpart to Api\Admin\BrandController. Four Invoice-module
// permissions gate it — canViewBrands / canCreateBrands / canEditBrands /
// canDeleteBrands — and none of them is a role default: Company Admin hands
// them to whoever maintains the brand list.
//
// Always one company: whichever the caller is currently working under
// (X-Active-Company-Id, validated against an ACTIVE assignment). Brands of
// any other company are neither listed nor reachable by id.
class BrandController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    private function companyId(): int
    {
        $user = $this->user();
        $requested = (int) request()->header('X-Active-Company-Id');

        if ($requested && CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $requested)
            ->where('status', 'active')
            ->exists()) {
            return $requested;
        }

        return (int) $user->company_id;
    }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        $companyId = $this->companyId();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('module_key', 'invoice')
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $companyId, $user->role_type, 'invoice', $permKey, $result);
        return $result;
    }

    // Only the Admin role keeps the brand list. Company Admin does it from
    // their own portal (the admin guard is their gate); on the staff side the
    // 'admin' role is the delegated brand keeper, which is exactly what it was
    // created for (RoleDefaultPermissions::MAP['admin']).
    //
    // Everyone else — Sellers, Lead Managers, anyone a brand is ASSIGNED to —
    // gets the brand read-only, plus the right to pick it while raising an
    // invoice. They never create, edit, delete, deactivate or reassign one.
    private function isBrandKeeper(): bool
    {
        return $this->user()->role_type === 'admin';
    }

    // A write on a brand needs both: the Admin role AND the matching
    // permission, so a Company Admin can still withhold (say) deletion from
    // an Admin without having to take the role away.
    private function refuseUnlessBrandKeeper(string $permKey): ?JsonResponse
    {
        if (!$this->isBrandKeeper()) {
            return ApiResponse::error(
                'Only your Company Admin or an Admin can change brands. You can view the brands assigned to you and select them when creating an invoice.',
                403
            );
        }

        if (!$this->can($permKey)) {
            return ApiResponse::error('Permission denied', 403);
        }

        return null;
    }

    // What this caller may SEE. The brand keeper sees the company's whole
    // brand list; everyone else sees only what was assigned to them — a
    // Seller has no business browsing brands they don't invoice under.
    private function visibleBrands()
    {
        $q = Brand::where('company_id', $this->companyId());

        if (!$this->isBrandKeeper()) {
            $q->whereHas('assignedUsers', fn ($u) => $u->where('users.id', $this->user()->id));
        }

        return $q;
    }

    // Viewing is open to the brand keeper (with canViewBrands) and to anyone
    // a brand is assigned to — being handed a brand to invoice under is
    // itself the right to see it.
    private function canViewBrands(): bool
    {
        if ($this->isBrandKeeper()) {
            return $this->can('canViewBrands');
        }

        return Brand::where('company_id', $this->companyId())
            ->whereHas('assignedUsers', fn ($u) => $u->where('users.id', $this->user()->id))
            ->exists();
    }

    private function format(Brand $brand): array
    {
        return [
            'id'         => $brand->id,
            'company_id' => $brand->company_id,
            'company'    => $brand->company ? ['id' => $brand->company->id, 'name' => $brand->company->name] : null,
            'name'       => $brand->name,
            'email'      => $brand->email,
            'phone'      => $brand->phone,
            'website'    => $brand->website,
            'country'    => $brand->country,
            'address'    => $brand->address,
            'logo_url'   => PublicFile::url($brand->logo_path),
            'is_active'  => (bool) $brand->is_active,
            'assigned_users' => $brand->assignedUsers
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values(),
            'created_by' => $brand->createdByAdmin?->name ?? $brand->createdBy?->name,
            'created_at' => $brand->created_at,
        ];
    }

    private function logAudit(Request $request, int $companyId, string $action, ?int $entityId, array $new = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => $this->user()->id,
            'action'      => $action,
            'module_key'  => 'invoice',
            'entity_type' => 'Brand',
            'entity_id'   => $entityId,
            'new_values'  => $new ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    // Every route below resolves the brand through this, so a brand belonging
    // to another company is a 404 rather than something to be edited.
    private function findOwn(int $id): ?Brand
    {
        return $this->visibleBrands()
            ->with(['company:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'assignedUsers:id,name,email'])
            ->find($id);
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->canViewBrands()) {
            return ApiResponse::error('Permission denied', 403);
        }

        $q = $this->visibleBrands()
            ->with(['company:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'assignedUsers:id,name,email'])
            ->orderBy('name');

        if ($request->boolean('active')) {
            $q->active();
        }

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $q->where(fn ($x) => $x->where('name', 'like', $s)
                ->orWhere('email', 'like', $s)
                ->orWhere('country', 'like', $s)
                ->orWhere('phone', 'like', $s));
        }

        return ApiResponse::success(['brands' => $q->get()->map(fn ($b) => $this->format($b))]);
    }

    public function show(int $id): JsonResponse
    {
        if (!$this->canViewBrands()) {
            return ApiResponse::error('Permission denied', 403);
        }

        $brand = $this->findOwn($id);
        if (!$brand) {
            return ApiResponse::error('Brand not found', 404);
        }

        return ApiResponse::success($this->format($brand));
    }

    public function store(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canCreateBrands')) {
            return $refusal;
        }

        $companyId = $this->companyId();

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:150'],
            'email'     => ['nullable', 'email', 'max:190'],
            'phone'     => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            // Normalised on save (Support\Website) so it is always a usable
            // absolute href on the emailed and public copies of an invoice.
            'website'   => ['nullable', 'string', 'max:190'],
            'country'   => ['nullable', 'string', 'max:100'],
            'address'   => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'logo'      => ['nullable', 'file', 'mimetypes:image/*', 'max:2048'],
        ], [
            'logo.mimetypes' => 'The logo must be an image file.',
            'logo.max'       => 'The logo must be 2 MB or smaller.',
        ]);

        // company_id is never accepted from the request here — a staff member
        // files brands under the company they are working in, full stop.
        if (Brand::where('company_id', $companyId)->where('name', $validated['name'])->exists()) {
            return ApiResponse::error('A brand with this name already exists in this company.', 422);
        }

        $brand = Brand::create([
            'company_id' => $companyId,
            'name'       => $validated['name'],
            'email'      => $validated['email']   ?? null,
            'phone'      => $validated['phone']   ?? null,
            'website'    => Website::normalise($validated['website'] ?? null),
            'country'    => $validated['country'] ?? null,
            'address'    => $validated['address'] ?? null,
            'is_active'  => $validated['is_active'] ?? true,
            'created_by' => $this->user()->id,
        ]);

        if ($request->hasFile('logo')) {
            $brand->update(['logo_path' => $request->file('logo')->store("companies/{$companyId}/brands", 'public')]);
        }

        // Every brand.* audit payload carries `brand` (the name) — the
        // notification feed reads it so an admin sees WHICH brand was touched
        // instead of a bare "Brand #5".
        $this->logAudit($request, $companyId, 'brand.created', $brand->id, ['brand' => $brand->name]);

        return ApiResponse::success($this->format($brand->fresh()->load('company:id,name')), 'Brand created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canEditBrands')) {
            return $refusal;
        }

        $brand = $this->findOwn($id);
        if (!$brand) {
            return ApiResponse::error('Brand not found', 404);
        }

        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:150', Rule::unique('brands', 'name')
                ->where(fn ($q) => $q->where('company_id', $brand->company_id)->whereNull('deleted_at'))
                ->ignore($brand->id)],
            'email'     => ['nullable', 'email', 'max:190'],
            'phone'     => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'website'   => ['nullable', 'string', 'max:190'],
            'country'   => ['nullable', 'string', 'max:100'],
            'address'   => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'logo'      => ['nullable', 'file', 'mimetypes:image/*', 'max:2048'],
        ], [
            'logo.mimetypes' => 'The logo must be an image file.',
            'logo.max'       => 'The logo must be 2 MB or smaller.',
        ]);

        // array_key_exists, not isset(): clearing the website to null has to
        // save as null rather than being skipped.
        if (array_key_exists('website', $validated)) {
            $validated['website'] = Website::normalise($validated['website']);
        }

        $brand->update(collect($validated)->except('logo')->toArray());

        if ($request->hasFile('logo')) {
            if ($brand->logo_path && Storage::disk('public')->exists($brand->logo_path)) {
                Storage::disk('public')->delete($brand->logo_path);
            }
            $brand->update(['logo_path' => $request->file('logo')->store("companies/{$brand->company_id}/brands", 'public')]);
        }

        $this->logAudit($request, $brand->company_id, 'brand.updated', $brand->id, array_merge(
            ['brand' => $brand->name],
            collect($validated)->except('logo')->toArray()
        ));

        return ApiResponse::success($this->format($brand->fresh()->load('company:id,name')), 'Brand updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canDeleteBrands')) {
            return $refusal;
        }

        $brand = $this->findOwn($id);
        if (!$brand) {
            return ApiResponse::error('Brand not found', 404);
        }

        $companyId = $brand->company_id;
        $name      = $brand->name;
        $brand->delete();

        $this->logAudit($request, $companyId, 'brand.deleted', $id, ['brand' => $name]);

        return ApiResponse::success(null, 'Brand deleted');
    }

    // Activating/deactivating is an edit, not a deletion — canEditBrands.
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canEditBrands')) {
            return $refusal;
        }

        $brand = $this->findOwn($id);
        if (!$brand) {
            return ApiResponse::error('Brand not found', 404);
        }

        $brand->update(['is_active' => !$brand->is_active]);
        $this->logAudit($request, $brand->company_id, $brand->is_active ? 'brand.activated' : 'brand.deactivated', $brand->id, ['brand' => $brand->name]);

        return ApiResponse::success($this->format($brand), 'Brand updated');
    }

    // Staff of this company holding at least one Invoice create/manage
    // permission — the Assign dropdown's contents. Same rule (and same
    // constant) as Api\Admin\BrandController, so both portals offer exactly
    // the same people: Seller and Lead Manager qualify through their role
    // defaults, a view-only invoice user never does.
    private function assignableUsersQuery(int $companyId)
    {
        return User::where('role_type', '!=', 'client')
            ->whereHas('companyAssignments', fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))
            ->whereHas('userCompanyPermissions', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('module_key', 'invoice')
                ->whereIn('permission_key', AdminBrandController::ASSIGNABLE_INVOICE_PERMISSIONS))
            ->select('id', 'name', 'email', 'role_type', 'custom_role_label')
            ->orderBy('name');
    }

    // Only the brand keeper assigns, so only they need the list of who can be
    // assigned — an assigned Seller never sees this.
    public function assignableUsers(): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canEditBrands')) {
            return $refusal;
        }

        return ApiResponse::success(
            $this->assignableUsersQuery($this->companyId())->get()->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->custom_role_label ?: $u->role_type,
            ])
        );
    }

    // Assigning is an edit of the brand, so canEditBrands — and the target is
    // re-checked against the same query that built the dropdown.
    public function assign(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canEditBrands')) {
            return $refusal;
        }

        $brand = $this->findOwn($id);
        if (!$brand) {
            return ApiResponse::error('Brand not found', 404);
        }

        // The full set that should work this brand, same replace-the-list
        // shape as Api\Admin\BrandController::assign().
        $validated = $request->validate([
            'user_ids'   => ['present', 'array'],
            'user_ids.*' => ['integer'],
        ]);

        $requested = array_values(array_unique(array_map('intval', $validated['user_ids'])));

        if ($requested) {
            $eligible = $this->assignableUsersQuery($brand->company_id)
                ->whereIn('users.id', $requested)->pluck('id')->all();

            if (count($eligible) !== count($requested)) {
                return ApiResponse::error(
                    'One or more of those users cannot be assigned this brand — they need Invoice create/manage permission in this company.',
                    422
                );
            }
        }

        $brand->assignedUsers()->sync($requested);

        // Names as well as ids: the notification feed spells out who the brand
        // went to, and ids alone would leave it reading "Brand #5".
        $assigneeNames = $requested
            ? User::whereIn('id', $requested)->orderBy('name')->pluck('name')->all()
            : [];

        $this->logAudit($request, $brand->company_id, 'brand.assigned', $brand->id, [
            'brand' => $brand->name, 'user_ids' => $requested, 'users' => $assigneeNames,
        ]);

        return ApiResponse::success(
            $this->format($brand->fresh()->load(['company:id,name', 'assignedUsers:id,name,email'])),
            $requested ? 'Brand assignment updated' : 'Brand unassigned'
        );
    }

    // ── Transfer to another company ──────────────────────────────────────────
    //
    // Every other route on this controller works in ONE company (the active
    // one). Transfer is the single exception: it needs a second company, so
    // it gets its own scope rather than widening companyId() for everything.
    //
    // Where a brand keeper may move a brand TO: companies they hold an ACTIVE
    // assignment in AND hold canEditBrands in — the same right that lets them
    // maintain the brand list there. Deliberately not "every company I'm
    // assigned to": a brand must not land somewhere this person couldn't then
    // look after it. The source company is naturally in this list too (they
    // are editing the brand there), and is rejected as a destination below.
    private function transferableCompanyIds(): array
    {
        $me = $this->user();

        $activeCompanyIds = CompanyUserAssignment::where('user_id', $me->id)
            ->where('status', 'active')
            ->pluck('company_id');

        $eligible = UserCompanyPermission::where('user_id', $me->id)
            ->where('module_key', 'invoice')
            ->where('permission_key', 'canEditBrands')
            ->whereIn('company_id', $activeCompanyIds)
            ->pluck('company_id')
            ->unique()
            ->values();

        // A suspended/deactivated company (Company::isOperational()) is
        // never a valid transfer destination — mirrors Api\Admin\
        // BrandController::transfer()'s identical restriction on that side.
        return Company::whereIn('id', $eligible)->operational()->pluck('id')->all();
    }

    // GET /user/brands/company-options — the Transfer panel's destination
    // dropdown. The page hides the panel entirely when this comes back with
    // fewer than two entries, so a single-company brand keeper never sees it.
    public function companyOptions(): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canEditBrands')) {
            return $refusal;
        }

        return ApiResponse::success(
            Company::whereIn('id', $this->transferableCompanyIds())
                ->select('id', 'name')->orderBy('id')->get()
        );
    }

    // PATCH /user/brands/{id}/transfer — staff counterpart to
    // Api\Admin\BrandController::transfer(), same rules and same wording, only
    // the set of allowed companies differs (assignment + canEditBrands here,
    // ownership there).
    //
    // Invoices already raised under the brand are LEFT ALONE and keep
    // pointing at it, so their letterhead still reads the same name and logo
    // after the move; the response says how many those are so the move is
    // never silent about it. Assignees who aren't staff of the destination
    // are dropped — they could no longer invoice under it there anyway.
    public function transfer(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refuseUnlessBrandKeeper('canEditBrands')) {
            return $refusal;
        }

        // Resolved through visibleBrands() like every other route, so the
        // brand must be in the company this keeper is currently working under.
        $brand = $this->findOwn($id);
        if (!$brand) {
            return ApiResponse::error('Brand not found', 404);
        }

        $companyIds = $this->transferableCompanyIds();

        // Nowhere to move it to — checked before validate() because an empty
        // Rule::in list would answer with a bare validation error instead of
        // saying why.
        if (count(array_diff($companyIds, [$brand->company_id])) === 0) {
            return ApiResponse::error(
                'There is no other company you can move this brand to — you need brand edit rights in the destination company.',
                422
            );
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', Rule::in($companyIds)],
        ]);

        $target = (int) $validated['company_id'];

        if ($target === $brand->company_id) {
            return ApiResponse::error('This brand is already in that company.', 422);
        }

        $invoiceCount = Invoice::where('brand_id', $brand->id)->count();

        // The one hard stop: brands are unique per company by name, so a
        // clash would fail at the database.
        if (Brand::where('company_id', $target)->where('name', $brand->name)->exists()) {
            return ApiResponse::error('A brand with this name already exists in the destination company.', 422);
        }

        $from = Company::find($brand->company_id)?->name ?? "Company #{$brand->company_id}";
        $to   = Company::find($target)?->name ?? "Company #{$target}";

        $keep = $this->assignableUsersQuery($target)
            ->whereIn('users.id', $brand->assignedUsers()->pluck('users.id'))
            ->pluck('id')->all();
        $dropped = $brand->assignedUsers()->count() - count($keep);

        DB::transaction(function () use ($brand, $target, $keep) {
            $brand->update(['company_id' => $target]);
            $brand->assignedUsers()->sync($keep);
        });

        $this->logAudit($request, $target, 'brand.transferred', $brand->id, [
            'brand' => $brand->name, 'from' => $from, 'to' => $to, 'invoices_kept' => $invoiceCount,
        ]);

        $notes = [];
        if ($dropped > 0) {
            $notes[] = "{$dropped} assignee(s) were removed — they are not staff of that company";
        }
        if ($invoiceCount > 0) {
            $notes[] = "{$invoiceCount} existing invoice(s) keep this brand's details";
        }

        return ApiResponse::success(
            $this->format($brand->fresh()->load(['company:id,name', 'assignedUsers:id,name,email'])),
            "Brand moved to {$to}." . ($notes ? ' ' . ucfirst(implode('; ', $notes)) . '.' : '')
        );
    }

    // What this caller may do, so the UI can hide buttons rather than let
    // them 403 — the same four keys every route above checks.
    public function permissions(): JsonResponse
    {
        return ApiResponse::success([
            // Writes are the brand keeper's alone, so the page hides those
            // controls entirely for an assigned Seller rather than letting
            // them click into a 403.
            'can_view'   => $this->canViewBrands(),
            'can_create' => $this->isBrandKeeper() && $this->can('canCreateBrands'),
            'can_edit'   => $this->isBrandKeeper() && $this->can('canEditBrands'),
            'can_delete' => $this->isBrandKeeper() && $this->can('canDeleteBrands'),
            // Tells the page it is showing an assigned-brand, read-only view
            // rather than the company's brand list.
            'view_only'  => !$this->isBrandKeeper(),
        ]);
    }
}
