<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Rules\ValidPhoneNumber;
use App\Support\PublicFile;
use App\Support\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// Brands — the trading names a company bills under, part of the Invoice
// module. Company Admin side; the staff counterpart (permission-gated, single
// company) is Api\User\BrandController and the two must stay in step.
class BrandController extends Controller
{
    use ScopesToActiveCompany;

    // Who may be assigned a brand: staff who can actually DO something with
    // invoices, not merely read them. Holding any one of these in the brand's
    // company is enough. Seller and Lead Manager both qualify through their
    // role defaults (canCreateInvoices/canEditInvoices and more — see
    // RoleDefaultPermissions::MAP), which is why the rule is stated in
    // permissions rather than roles: a Company Admin who grants invoice
    // rights to some other role gets that person in the list too, and a
    // view-only user never appears.
    public const ASSIGNABLE_INVOICE_PERMISSIONS = [
        'canCreateInvoices', 'canEditInvoices', 'canDeleteOrCancelInvoices',
        'canSendInvoices', 'canRecordPayments', 'canManageBillingClients',
        'canManageInvoiceSettings',
    ];

    private function admin()
    {
        return auth('admin')->user();
    }

    // Staff of one company holding at least one of the permissions above.
    // Suspended assignments are excluded — a suspended user can't work the
    // brand, so offering them would be a dead choice.
    private function assignableUsersQuery(int $companyId)
    {
        return User::where('role_type', '!=', 'client')
            ->whereHas('companyAssignments', fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))
            ->whereHas('userCompanyPermissions', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('module_key', 'invoice')
                ->whereIn('permission_key', self::ASSIGNABLE_INVOICE_PERMISSIONS))
            ->select('id', 'name', 'email', 'role_type', 'custom_role_label')
            ->orderBy('name');
    }

    // Every company this admin owns. Reads/writes are narrowed further to the
    // active company (activeCompanyIds()) exactly like ClientController and
    // ProjectController — but ownership is always checked against this wider
    // list first, so a tampered company_id can never reach another tenant.
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
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
            // Everyone working this brand — several people can share one
            // trading name, so this is always a list.
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
            // SystemAuditLog.user_id FKs to `users`; a Company Admin has no
            // row there, so the actor is carried by the action itself.
            'user_id'     => null,
            'action'      => $action,
            'module_key'  => 'invoice',
            'entity_type' => 'Brand',
            'entity_id'   => $entityId,
            'new_values'  => $new ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Brand::whereIn('company_id', $this->activeCompanyIds())
            ->with(['company:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'assignedUsers:id,name,email'])
            ->orderBy('name');

        // Pickers ask for ?active=1; the management screen shows everything.
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
        $brand = Brand::whereIn('company_id', $this->companyIds())
            ->with(['company:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'assignedUsers:id,name,email'])
            ->findOrFail($id);

        return ApiResponse::success($this->format($brand));
    }

    public function store(Request $request): JsonResponse
    {
        // operationalCompanyIds(): a new Brand can never be raised under a
        // company its own Admin has suspended (or Super Admin deactivated) —
        // see Company::isOperational().
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            // Defaults to the active company, so a single-company admin never
            // has to pick one.
            'company_id' => ['nullable', 'integer', 'in:' . implode(',', $companyIds)],
            'name'       => ['required', 'string', 'max:150'],
            'email'      => ['nullable', 'email', 'max:190'],
            'phone'      => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            // Stored normalised (Support\Website) so it is always a usable
            // absolute href — see that class for why not at render time.
            'website'    => ['nullable', 'string', 'max:190'],
            'country'    => ['nullable', 'string', 'max:100'],
            'address'    => ['nullable', 'string', 'max:1000'],
            'is_active'  => ['nullable', 'boolean'],
            'logo'       => ['nullable', 'file', 'mimetypes:image/*', 'max:2048'],
        ], [
            'logo.mimetypes' => 'The logo must be an image file.',
            'logo.max'       => 'The logo must be 2 MB or smaller.',
        ]);

        $companyId = (int) ($validated['company_id'] ?? $this->activeCompanyIds()[0] ?? $companyIds[0] ?? 0);
        if (!$companyId) {
            return ApiResponse::error('No company found', 404);
        }

        // The DB unique index would throw a raw duplicate-key error; this
        // answers with something the form can show against the Name field.
        if (Brand::where('company_id', $companyId)->where('name', $validated['name'])->exists()) {
            return ApiResponse::error('A brand with this name already exists in this company.', 422);
        }

        $brand = Brand::create([
            'company_id'          => $companyId,
            'name'                => $validated['name'],
            'email'               => $validated['email']   ?? null,
            'phone'               => $validated['phone']   ?? null,
            'website'             => Website::normalise($validated['website'] ?? null),
            'country'             => $validated['country'] ?? null,
            'address'             => $validated['address'] ?? null,
            'is_active'           => $validated['is_active'] ?? true,
            'created_by_admin_id' => $this->admin()->id,
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
        $brand = Brand::whereIn('company_id', $this->companyIds())->findOrFail($id);

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

        // Only when the field was actually sent — array_key_exists, not
        // isset(), so clearing the website through to null still saves.
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

    // Soft delete: an invoice already raised under this brand keeps pointing
    // at a row that still exists, so its history stays readable.
    public function destroy(Request $request, int $id): JsonResponse
    {
        $brand = Brand::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $companyId = $brand->company_id;
        $name      = $brand->name;
        $brand->delete();

        $this->logAudit($request, $companyId, 'brand.deleted', $id, ['brand' => $name]);

        return ApiResponse::success(null, 'Brand deleted');
    }

    // Separate from update() so the list's toggle is one call with nothing
    // else in the payload that could be overwritten by a stale form.
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $brand = Brand::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $brand->update(['is_active' => !$brand->is_active]);

        $this->logAudit($request, $brand->company_id, $brand->is_active ? 'brand.activated' : 'brand.deactivated', $brand->id, ['brand' => $brand->name]);

        return ApiResponse::success($this->format($brand->load('company:id,name')), 'Brand updated');
    }

    // GET /admin/brands/assignable-users?company_id= — what the Assign column's
    // dropdown offers. Per company, because the permission that qualifies
    // someone is itself per company: the same person can be an invoice user in
    // one of this admin's companies and not in another.
    public function assignableUsers(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'in:' . implode(',', $companyIds)],
        ]);

        $companyId = (int) ($validated['company_id'] ?? $this->activeCompanyIds()[0] ?? $companyIds[0] ?? 0);
        if (!$companyId) {
            return ApiResponse::error('No company found', 404);
        }

        return ApiResponse::success(
            $this->assignableUsersQuery($companyId)->get()->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->custom_role_label ?: $u->role_type,
            ])
        );
    }

    // PATCH /admin/brands/{id}/assign — the FULL list of user_ids that should
    // work this brand (an empty array unassigns everyone), the same
    // replace-the-set shape the project team editor uses. Every id is
    // re-checked against the query that built the picker, so a stale or
    // hand-crafted one can't hand a brand to someone without invoice rights
    // (or to another company's staff).
    public function assign(Request $request, int $id): JsonResponse
    {
        $brand = Brand::whereIn('company_id', $this->companyIds())->findOrFail($id);

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

    // PATCH /admin/brands/{id}/transfer — move a brand to another company
    // this same admin owns (raised under the wrong one, or the trading name
    // genuinely moved). Company Admin only: a staff member never sees more
    // than one company here.
    //
    // Invoices already raised under the brand are LEFT ALONE and keep
    // pointing at it, so their letterhead still reads the same name and logo
    // after the move — the brand record itself doesn't change, only which
    // company owns it going forward. The response says how many those are, so
    // the move is never silent about it.
    //
    // Assignees who aren't staff of the destination company are dropped —
    // they could no longer raise an invoice under it there anyway.
    public function transfer(Request $request, int $id): JsonResponse
    {
        // Looking up the EXISTING brand still uses every owned company (it
        // may legitimately live under one that's since been suspended — this
        // is how it gets moved OUT). Only the destination the admin can move
        // it TO is restricted to an operational company.
        $companyIds = $this->companyIds();
        $brand = Brand::whereIn('company_id', $companyIds)->findOrFail($id);

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'in:' . implode(',', $this->operationalCompanyIds())],
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

        // Everyone who is also staff of the destination company keeps the
        // brand; the rest are dropped rather than left holding a brand they
        // can't invoice under.
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

    // Companies this admin can file a brand under — the Add form's picker.
    public function companyOptions(): JsonResponse
    {
        return ApiResponse::success(
            Company::whereIn('id', $this->companyIds())->select('id', 'name')->orderBy('id')->get()
        );
    }
}
