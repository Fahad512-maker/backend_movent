<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Models\Client;
use App\Models\ClientAiServiceBatch;
use App\Models\ClientPortalPermission;
use App\Models\ClientTransfer;
use App\Models\Company;
use App\Models\CompanyUserAssignment;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Mail\ClientPortalWelcomeMail;
use App\Rules\ValidPhoneNumber;
use App\Services\ClientDeletionService;
use App\Services\ClientPortalService;
use App\Support\CompanyName;
use App\Support\CrossAccountEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ClientController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()
    {
        return auth('admin')->user();
    }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // ─── Per-company seat check: each company has its own independent limit ──────
    private function seatInfo(int $companyId): array
    {
        $admin = $this->admin()->load('package');
        // Per-admin override (paid at registration or via a seat upgrade)
        // takes precedence over the shared Package default — same
        // precedence rule as companyInfo() below and UserController::orgUserLimit().
        $limit = $admin->max_users_per_company ?? $admin->package?->max_users_per_company;

        $portalUsed = User::where('company_id', $companyId)
            ->where('role_type', 'client')
            ->count();

        $clientRecords = Client::where('company_id', $companyId)->count();

        return [
            'limit'         => $limit,
            'portal_used'   => $portalUsed,
            'clients_total' => $clientRecords,
            'remaining'     => $limit !== null ? max($limit - $portalUsed, 0) : null,
            'can_add'       => $limit === null || $portalUsed < $limit,
        ];
    }

    // ─── Company limit check ──────────────────────────────────────────────────
    private function companyInfo(): array
    {
        $admin = $this->admin()->load('package');

        // The company count chosen (and paid for) at registration takes
        // precedence over the shared Package's default — falls back to the
        // Package limit only for admins registered before this column existed.
        $max  = $admin->max_companies ?? $admin->package?->max_companies ?? null;
        $used = $admin->companies()->count();

        return [
            'max'     => $max,
            'used'    => $used,
            'can_add' => $max === null || $used < $max,
        ];
    }

    // ─── Check client_portal module ───────────────────────────────────────────
    private function hasPortalModule(int $companyId): bool
    {
        return Company::find($companyId)
            ?->modules()
            ->whereIn('module_key', ['client_portal', 'clients'])
            ->where('is_enabled', true)
            ->exists() ?? false;
    }

    // ─── Cross-account email guard ─────────────────────────────────────────────
    // Blocks a Company Admin match outright, and a staff match UNLESS that
    // User is already role_type='client' (re-using/re-linking an existing
    // client login — e.g. the same person is a client of two companies — is
    // fine; silently hijacking a Seller/PM/QA/etc.'s login is not).
    private function emailBelongsToAnotherAccount(string $email): bool
    {
        if (CrossAccountEmail::existsAsAdmin($email)) {
            return true;
        }
        $user = User::where('email', $email)->first();
        return $user !== null && $user->role_type !== 'client';
    }

    // ─── Seed default permissions (all enabled) ───────────────────────────────
    private function seedPermissions(int $clientId): void
    {
        foreach (array_keys(ClientPortalPermission::MODULES) as $key) {
            ClientPortalPermission::firstOrCreate(
                ['client_id' => $clientId, 'module_key' => $key],
                ['is_enabled' => true]
            );
        }
    }

    // ─── Create / update portal user ──────────────────────────────────────────
    // Returns an error message on failure, null on success. Callers already
    // check emailBelongsToAnotherAccount() before reaching here — this is a
    // second, defense-in-depth guard against ever silently converting an
    // existing Admin/non-client staff login into a client login.
    private function createOrUpdatePortalUser(Client $client, string $email, string $password): ?string
    {
        if ($this->emailBelongsToAnotherAccount($email)) {
            return 'This email is already registered as a staff or Company Admin account.';
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'password'  => Hash::make($password),
                'role_type' => 'client',
                'is_active' => true,
            ]);
        } else {
            $user = User::create([
                'company_id' => $client->company_id,
                'name'       => $client->name,
                'email'      => $email,
                'password'   => Hash::make($password),
                'role_type'  => 'client',
                'is_active'  => true,
            ]);
        }

        $client->update(['user_id' => $user->id, 'portal_access' => true]);

        return null;
    }

    // =========================================================================
    // GET /api/admin/usage
    // =========================================================================
    public function usage(): JsonResponse
    {
        $admin   = $this->admin()->load('package');
        $package = $admin->package;

        // Effective limits — admin-level override (registration or seat/company
        // upgrade purchase) takes precedence over the Package default. Same
        // precedence as seatInfo()/companyInfo() above.
        $effectiveSeatLimit    = $admin->max_users_per_company ?? $package?->max_users_per_company;
        $effectiveCompanyLimit = $admin->max_companies ?? $package?->max_companies;

        $companies = $admin->companies()->withCount([
            'clients',
            // Matches seatInfo()'s enforcement query exactly (User.role_type =
            // 'client'), not Client.portal_access — those two flags can drift
            // apart since portal_access changes don't always keep the paired
            // User row's role_type in lockstep on every code path.
            'users as portal_clients_count' => fn($q) => $q->where('role_type', 'client'),
        ])->get(['id', 'name', 'is_active']);

        $comp = $this->companyInfo();

        // Staff seats — an ORG-WIDE pool (one shared cap across every company
        // this admin owns), not per-company. Mirrors
        // Admin\UserController::orgUserCount()/orgUserLimit() exactly — that's
        // the endpoint that actually enforces this limit when creating/
        // inviting staff, this is just read-only display of the same numbers.
        // Deliberately distinct from the per-company "portal seat" limit
        // below (client-portal logins), which is a different business rule
        // that happens to reuse the same $effectiveSeatLimit value.
        // status='active' filter: a user suspended in every company must
        // free their seat, exactly like orgUserCount().
        $staffSeatsUsed = CompanyUserAssignment::whereIn('company_id', $this->companyIds())
            ->where('status', 'active')
            ->distinct('user_id')->count('user_id') + 1; // +1 for the admin themselves
        $staffSeatsRemaining = $effectiveSeatLimit !== null ? max(0, $effectiveSeatLimit - $staffSeatsUsed) : null;

        // Per-company breakdown of active staff seats — via CompanyUserAssignment
        // (not the `users` relation, which only reflects a user's single
        // PRIMARY company) so a user active in two of this admin's companies
        // is correctly counted in BOTH company rows here, even though
        // $staffSeatsUsed above counts them only once org-wide.
        $staffCountsByCompany = CompanyUserAssignment::whereIn('company_id', $this->companyIds())
            ->where('status', 'active')
            ->selectRaw('company_id, COUNT(DISTINCT user_id) as cnt')
            ->groupBy('company_id')
            ->pluck('cnt', 'company_id');

        $payments = $admin->subscriptionPayments()->orderByDesc('created_at')->get();
        $paymentTypeLabels = [
            'module_purchase'       => 'Module Purchase',
            'seat_upgrade'          => 'Seat Upgrade',
            'company_slot_upgrade'  => 'Company Slot Upgrade',
        ];

        return ApiResponse::success([
            'package' => [
                'name'                  => $package?->name,
                'tier'                  => $package?->tier,
                'billing_cycle'         => $package?->billing_cycle,
                'max_companies'         => $effectiveCompanyLimit,
                'max_users_per_company' => $effectiveSeatLimit,
            ],
            'subscription' => [
                // Live-computed (not the raw column) — see CompanyAdmin::
                // effectiveSubscriptionStatus(): the stored value can lag up
                // to a day behind whatever the daily cron hasn't run yet, so
                // reading it directly here would show "trial" past its real
                // expiry with no explanation.
                'status'               => $admin->effectiveSubscriptionStatus(),
                'trial_started_at'     => $admin->created_at?->toDateString(),
                'trial_ends_at'        => $admin->trial_ends_at?->toDateString(),
                'subscription_ends_at' => $admin->subscription_ends_at?->toDateString(),
                'grace_period_ends_at' => $admin->effectiveGracePeriodEndsAt()?->toDateString(),
                'suspended_reason'     => $admin->suspended_reason,
                'trial_days_remaining' => $admin->trialDaysRemaining(),
                'grace_days_remaining' => $admin->graceDaysRemaining(),
            ],
            'staff_seats_used'      => $staffSeatsUsed,
            'staff_seats_limit'     => $effectiveSeatLimit,
            'staff_seats_remaining' => $staffSeatsRemaining,
            'companies_used'  => $comp['used'],
            'companies_max'   => $comp['max'],
            'can_add_company' => $comp['can_add'],
            'companies'       => $companies->map(fn($c) => [
                'id'                   => $c->id,
                'name'                 => $c->name,
                'is_active'            => $c->is_active,
                'clients_count'        => $c->clients_count,        // total client records
                'portal_clients_count' => $c->portal_clients_count, // portal-enabled clients
                'active_staff_count'   => $staffCountsByCompany[$c->id] ?? 0, // staff seats used in this company
            ]),
            'payments_total_paid' => $payments->where('status', 'paid')->sum('amount'),
            'payments' => $payments->map(fn($p) => [
                'id'         => $p->id,
                'amount'     => $p->amount,
                'currency'   => $p->currency,
                'gateway'    => $p->gateway,
                'status'     => $p->status,
                // No `meta.type` means the monthly subscription-renewal charge
                // (the only write path that never sets one — see SubscriptionPaymentController).
                'label'      => $paymentTypeLabels[$p->meta['type'] ?? ''] ?? 'Subscription Renewal',
                'created_at' => $p->created_at?->toIso8601String(),
            ]),
        ]);
    }

    // =========================================================================
    // GET /api/admin/companies  (dropdown list)
    // =========================================================================
    public function companies(): JsonResponse
    {
        // Every picker/switcher this feeds (Company Selector, New Lead/Client/
        // Invoice company dropdowns — see the frontend callers of this same
        // endpoint) must never offer a suspended company as a workable
        // target, so this stays scoped to operational() the same way it
        // already was to is_active alone. manageCompanies() below is the
        // deliberately-unfiltered counterpart for the company-management
        // screen itself, where a suspended company still needs to be seen
        // (to reactivate it).
        $companies = $this->admin()->companies()
            ->select('id', 'name', 'currency')
            ->operational()
            ->orderBy('name')
            ->get();

        return ApiResponse::success($companies);
    }

    // GET /admin/companies/manage — full list INCLUDING suspended/deactivated
    // companies, for the company-management screen (status, suspension
    // reason/date, Suspend/Reactivate). Deliberately a separate endpoint from
    // companies() above rather than a query flag on it, so every existing
    // picker/switcher caller keeps getting the filtered list without having
    // to be updated to pass a new param.
    public function manageCompanies(): JsonResponse
    {
        // admin_id/created_at are what let the screen show "Created <date> by
        // <admin>" — a Company can only ever be created by the Admin who owns
        // it (storeCompany() below is admin-guard-only; no staff route exists
        // to create one), so admin_id IS the creator, with no separate
        // created_by column needed (unlike Employee/Client/Lead, which staff
        // can also create).
        $companies = $this->admin()->companies()
            ->select('id', 'name', 'currency', 'is_active', 'suspended_at', 'suspension_reason', 'admin_id', 'created_at')
            ->with('admin:id,name')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($companies);
    }

    // GET /admin/companies/{id}/history — this company's own lifecycle audit
    // trail (created/updated/suspended/reactivated), for the company-
    // management screen's History panel. Scoped to entity_type=Company so it
    // never mixes in the unrelated activity (invoices, employees, etc.) that
    // also carries this same company_id in system_audit_logs.
    public function companyHistory(int $id): JsonResponse
    {
        $company = $this->admin()->companies()->findOrFail($id);

        $history = SystemAuditLog::where('company_id', $company->id)
            ->where('entity_type', Company::class)
            ->where('entity_id', $company->id)
            ->orderByDesc('created_at')
            ->get(['action', 'old_values', 'new_values', 'created_at']);

        return ApiResponse::success($history);
    }

    // =========================================================================
    // POST /api/admin/companies  (create new company)
    // =========================================================================
    public function storeCompany(Request $request): JsonResponse
    {
        $admin = $this->admin();
        $comp  = $this->companyInfo();

        if (!$comp['can_add']) {
            return ApiResponse::error(
                "Company limit reached ({$comp['used']}/{$comp['max']}). Please upgrade your package.",
                422
            );
        }

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:200'],
            // currency is deliberately NOT accepted here — every company is
            // created in USD, the system's only supported currency now.
            'industry' => 'nullable|string|max:100',
            'email'    => 'nullable|email|max:255',
            'phone'    => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'address'  => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:100',
            'country'  => 'nullable|string|max:5',
        ]);

        $data['name'] = CompanyName::normalize($data['name']);
        CompanyName::throwIfTaken($data['name'], 'name', $admin->id);

        $company = Company::create([
            'admin_id'       => $admin->id,
            'name'           => $data['name'],
            'currency'       => 'USD',
            'industry'       => $data['industry'] ?? null,
            'email'          => $data['email'] ?? null,
            'phone'          => $data['phone'] ?? null,
            'address'        => $data['address'] ?? null,
            'timezone'       => $data['timezone'] ?? 'Asia/Karachi',
            'country'        => $data['country'] ?? null,
            'storage_folder' => 'companies/0/',
            'is_active'      => true,
        ]);

        $folder = 'companies/' . $company->id . '/';
        $company->update(['storage_folder' => $folder]);
        Storage::makeDirectory($folder);

        // Copy modules from the admin's first existing company
        $firstCompany = $admin->companies()
            ->where('id', '!=', $company->id)
            ->first();

        if ($firstCompany) {
            $firstCompany->modules()->get()->each(function ($mod) use ($company) {
                $company->modules()->create([
                    'module_key' => $mod->module_key,
                    'is_enabled' => $mod->is_enabled,
                ]);
            });

            // ...and its invoicing profile (invoice defaults, bank details,
            // logo) for the same reason: settings are per-company now, so a
            // new company would otherwise start at the raw column defaults
            // and the admin would have to retype their whole profile. A COPY
            // only — editing either company's Settings afterwards never
            // touches the other. See App\Support\SettingsPayload::
            // seedFromSibling().
            \App\Support\SettingsPayload::seedFromSibling($company, $firstCompany);
        }

        SystemAuditLog::create([
            'company_id'  => $company->id,
            'user_id'     => null,
            'action'      => 'company.created',
            'module_key'  => 'company',
            'entity_type' => Company::class,
            'entity_id'   => $company->id,
            'new_values'  => ['name' => $company->name],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success([
            'id'   => $company->id,
            'name' => $company->name,
        ], 'Company created successfully');
    }

    // =========================================================================
    // GET /api/admin/companies/{id}  (full details, for the edit form)
    // =========================================================================
    public function showCompany(int $id): JsonResponse
    {
        $company = $this->admin()->companies()->findOrFail($id);

        return ApiResponse::success(
            $company->only(['id', 'name', 'industry', 'email', 'phone', 'address', 'timezone', 'country', 'currency', 'is_active'])
        );
    }

    // =========================================================================
    // PUT /api/admin/companies/{id}
    // =========================================================================
    public function updateCompany(Request $request, int $id): JsonResponse
    {
        $company = $this->admin()->companies()->findOrFail($id);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:200'],
            // currency is deliberately NOT accepted here — the system only
            // supports USD now, and an existing company's currency (whatever
            // it already is) is left untouched rather than silently
            // relabeled, since that would misrepresent its historical
            // invoices' real currency.
            'industry' => 'nullable|string|max:100',
            'email'    => 'nullable|email|max:255',
            'phone'    => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'address'  => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:100',
            'country'  => 'nullable|string|max:5',
        ]);

        $data['name'] = CompanyName::normalize($data['name']);
        CompanyName::throwIfTaken($data['name'], 'name', $this->admin()->id, $company->id);

        $before = $company->only(array_keys($data));
        $company->update($data);

        // Only the fields that actually changed — a no-op re-save of the
        // same values (e.g. re-submitting the edit form untouched) shouldn't
        // clutter the history with an empty "updated" entry.
        $changed = array_diff_assoc($company->only(array_keys($data)), $before);
        if (!empty($changed)) {
            SystemAuditLog::create([
                'company_id'  => $company->id,
                'user_id'     => null,
                'action'      => 'company.updated',
                'module_key'  => 'company',
                'entity_type' => Company::class,
                'entity_id'   => $company->id,
                'old_values'  => array_intersect_key($before, $changed),
                'new_values'  => $changed,
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
            ]);
        }

        return ApiResponse::success(
            $company->only(['id', 'name', 'industry', 'email', 'phone', 'address', 'timezone', 'country', 'currency']),
            'Company updated successfully'
        );
    }

    // =========================================================================
    // PATCH /api/admin/companies/{id}/suspend
    //
    // Manual, per-Company suspension — see Company::isOperational() for how
    // this stays independent of is_active (Super Admin's own toggle) and of
    // this tenant's own subscription status. Blocks normal access/business
    // operations for just this one company (enforced by the
    // 'company.not_suspended' middleware — see routes/api.php and
    // EnsureCompanyNotSuspended) while leaving every record intact and every
    // other company this Admin owns untouched.
    // =========================================================================
    public function suspendCompany(Request $request, int $id): JsonResponse
    {
        $company = $this->admin()->companies()->findOrFail($id);

        if ($company->isSuspended()) {
            return ApiResponse::error('This company is already suspended.', 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $previousStatus = $company->is_active ? 'active' : 'inactive';

        $company->update([
            'suspended_at'      => now(),
            'suspension_reason' => $data['reason'],
        ]);

        $this->logCompanyStatusChange($request, $company, 'company.suspended', $previousStatus, 'suspended', $data['reason']);

        return ApiResponse::success(
            $company->only(['id', 'name', 'is_active', 'suspended_at', 'suspension_reason']),
            'Company suspended successfully'
        );
    }

    // =========================================================================
    // PATCH /api/admin/companies/{id}/reactivate
    //
    // Only ever lifts THIS manual suspension — never touches is_active (Super
    // Admin's own restriction) or the tenant's subscription_status (billing
    // restriction), so either of those, if present, still blocks access
    // afterwards exactly as before. remaining_restriction below tells the
    // frontend which (if any) is still in effect, so it can point the Admin
    // at the right resolution flow instead of implying Reactivate failed.
    // =========================================================================
    public function reactivateCompany(Request $request, int $id): JsonResponse
    {
        $company = $this->admin()->companies()->findOrFail($id);

        if (!$company->isSuspended()) {
            return ApiResponse::error('This company is not suspended.', 422);
        }

        $company->update([
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        $this->logCompanyStatusChange($request, $company, 'company.reactivated', 'suspended', $company->is_active ? 'active' : 'inactive', null);

        $admin = $this->admin();
        $remainingRestriction = $admin->isBlockedFromAccess()
            ? 'subscription'
            : (!$company->is_active ? 'deactivated_by_super_admin' : null);

        return ApiResponse::success([
            'company'                => $company->only(['id', 'name', 'is_active', 'suspended_at', 'suspension_reason']),
            'fully_active'           => $remainingRestriction === null,
            'remaining_restriction'  => $remainingRestriction,
        ], 'Company reactivated successfully');
    }

    private function logCompanyStatusChange(Request $request, Company $company, string $action, string $oldStatus, string $newStatus, ?string $reason): void
    {
        SystemAuditLog::create([
            'company_id'  => $company->id,
            // SystemAuditLog.user_id FKs to `users`; a Company Admin has no
            // row there, so the actor is carried by the action itself (same
            // convention as Api\Admin\BrandController::logAudit()).
            'user_id'     => null,
            'action'      => $action,
            'module_key'  => 'company',
            'entity_type' => Company::class,
            'entity_id'   => $company->id,
            'old_values'  => ['status' => $oldStatus],
            'new_values'  => array_filter(['status' => $newStatus, 'reason' => $reason], fn ($v) => $v !== null),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    // =========================================================================
    // GET /api/admin/clients
    // =========================================================================
    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $query = Client::whereIn('company_id', $companyIds)
            ->with(['user:id,email,is_active', 'company:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('company_name', 'like', "%{$s}%")
            );
        }

        if ($request->filled('portal')) {
            $query->where('portal_access', $request->portal === 'enabled');
        }

        // Company-Wise Dashboard Filtering — defaults to the active company
        // (or every owned company when "All Companies" is selected),
        // narrowed further by an explicit ?company_id= override when given.
        if ($request->filled('company_id')) {
            $cid = (int) $request->company_id;
            if (in_array($cid, $companyIds, true)) {
                $query->where('company_id', $cid);
            } else {
                // Asking for a company this admin doesn't own must return
                // NOTHING. Previously this branch applied no narrowing at
                // all, so the query fell back to the whereIn() above and
                // answered with every company the admin owns — the opposite
                // of what was asked for, and a client from company B showing
                // up in what was meant to be company A's list.
                $query->whereRaw('1 = 0');
            }
            // Show seat info for the same company the list above was scoped to.
            $seatCompanyId = in_array($cid, $companyIds, true) ? $cid : ($companyIds[0] ?? null);
        } else {
            $query->whereIn('company_id', $this->activeCompanyIds());
            // Seat limits are inherently per-company — even when "All
            // Companies" is selected (activeCompanyIds() returns every
            // owned company), show seat info for the one active company
            // (activeCompanyId()'s own single-company resolution), not an
            // arbitrary/undefined pick.
            $seatCompanyId = $this->activeCompanyId() ?: ($companyIds[0] ?? null);
        }

        $clients = $query->get([
            'id', 'company_id', 'user_id', 'name', 'email', 'phone',
            'company_name', 'portal_access', 'status', 'created_at',
        ]);

        return ApiResponse::success([
            'clients' => $clients,
            'seat'    => $seatCompanyId ? $this->seatInfo($seatCompanyId) : null,
        ]);
    }

    // =========================================================================
    // GET /api/admin/clients/{id}
    // =========================================================================
    public function show(int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())
            ->with(['user:id,email,role_type,is_active', 'company.modules', 'createdBy:id,name', 'createdByAdmin:id,name', 'accountManager:id,name'])
            ->findOrFail($id);

        // Once this client has a Project, its chat conversation moved there
        // — see App\Services\PaymentProjectStartService::migrateChatHistory()
        // — so the frontend can point the Sales Chat tab at Project Chat
        // instead of the old, now-abandoned Client-anchored thread.
        $client->chat_project_id = $client->projects()->latest()->value('id');

        // What modules has this company purchased?
        $companyModules = $client->company->modules
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->toArray();

        $permissions = ClientPortalPermission::where('client_id', $id)
            ->get(['module_key', 'is_enabled'])
            ->keyBy('module_key')
            ->map(fn($p) => $p->is_enabled);

        // Return all portal modules; mark which ones are covered by the company's plan
        $allModules = [];
        foreach (ClientPortalPermission::MODULES as $key => $label) {
            $required   = ClientPortalService::PORTAL_TO_COMPANY[$key] ?? [$key];
            $purchased  = count(array_intersect((array) $required, $companyModules)) > 0;
            $allModules[$key] = [
                'label'      => $label,
                'is_enabled' => $purchased ? (bool) ($permissions[$key] ?? true) : false,
                'purchased'  => $purchased,
            ];
        }

        return ApiResponse::success([
            'client'      => $client,
            'permissions' => $allModules,
            'seat'        => $this->seatInfo($client->company_id),
            // Drives the frontend's Portal tab/"Enable Portal" button — a
            // company without the real Client Portal module only ever gets
            // a Basic Client record, portal login is never offerable.
            'has_portal_module' => in_array('client_portal', $companyModules, true),
        ]);
    }

    // GET /admin/clients/{id}/ai-service-batch-items — this client's
    // outstanding (not yet 'taken') AI-suggested services, for the "Link to
    // AI-Suggested Service" dropdown on Create Invoice. See
    // App\Services\ClientAiServiceBatchService.
    public function aiServiceBatchItems(int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $batch = ClientAiServiceBatch::where('client_id', $client->id)->active()->first();

        if (!$batch) {
            return ApiResponse::success(['batch_id' => null, 'items' => []]);
        }

        $items = $batch->items()
            ->where('status', '!=', 'taken')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'category', 'status']);

        return ApiResponse::success(['batch_id' => $batch->id, 'items' => $items]);
    }

    // POST /admin/clients/{id}/transfer — mirrors Api\Admin\LeadController::
    // transfer(), reassigning account_manager instead of a lead's assigned_to.
    // Sellers only (not Lead Manager/others) — narrower than Lead Transfer's
    // target set, by explicit request.
    public function transfer(Request $request, int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $validated = $request->validate([
            'to_user_id' => ['required', 'integer'],
            'reason'     => ['nullable', 'string', 'max:1000'],
        ]);

        $fromUserId = $client->account_manager;
        $toUserId   = $validated['to_user_id'];

        if ($fromUserId === $toUserId) {
            return ApiResponse::error('Client is already assigned to this user.', 422);
        }

        $isValidTarget = User::ofCompany($client->company_id)
            ->where('id', $toUserId)
            ->where('is_active', true)
            ->where('role_type', 'seller')
            ->exists();
        if (!$isValidTarget) {
            return ApiResponse::error('Selected user is not an eligible transfer target at this company.', 422);
        }

        ClientTransfer::create([
            'client_id'                 => $client->id,
            'company_id'                => $client->company_id,
            'from_user_id'              => $fromUserId,
            'to_user_id'                => $toUserId,
            'transferred_by_admin_name' => $this->admin()->name ?? 'Admin',
            'reason'                    => $validated['reason'] ?? null,
        ]);

        $client->update(['account_manager' => $toUserId]);

        $toName = User::find($toUserId)?->name ?? 'Unknown';
        Notification::create([
            'user_id'    => $toUserId,
            'company_id' => $client->company_id,
            'type'       => 'client_transferred',
            'title'      => 'Client assigned to you',
            'body'       => "Client \"{$client->name}\" was assigned to you" . (!empty($validated['reason']) ? " — {$validated['reason']}" : '') . '.',
            'data'       => ['client_id' => $client->id, 'link' => "/clients/{$client->id}"],
        ]);

        $client->load(['user:id,email,role_type,is_active', 'createdBy:id,name', 'createdByAdmin:id,name', 'accountManager:id,name']);
        return ApiResponse::success($client, 'Client transferred');
    }

    // GET /admin/clients/company-users?company_id= — picker list for the
    // Transfer Client modal. Sellers only — mirrors transfer()'s validation.
    public function companyUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'in:' . implode(',', $this->operationalCompanyIds())],
        ]);

        $users = User::ofCompany($data['company_id'])
            ->where('is_active', true)
            ->where('role_type', 'seller')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return ApiResponse::success($users);
    }

    // =========================================================================
    // POST /api/admin/clients
    // =========================================================================
    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $data = $request->validate([
            'company_id'      => 'required|integer|in:' . implode(',', $companyIds),
            'name'            => 'required|string|max:150',
            'email'           => 'nullable|email|max:255',
            'phone'           => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'company_name'    => 'nullable|string|max:150',
            'address'         => 'nullable|string|max:500',
            'country'         => 'nullable|string|max:5',
            'notes'           => 'nullable|string|max:1000',
            'status'          => 'nullable|in:active,inactive,blocked',
            'enable_portal'   => 'nullable|boolean',
            'portal_email'    => 'nullable|required_if:enable_portal,true|email|max:255',
            'portal_password' => 'nullable|required_if:enable_portal,true|string|min:6|max:100',
        ]);

        $companyId = (int) $data['company_id'];

        // A client's contact email must not collide with an existing staff
        // or Admin login — otherwise enabling portal access for this client
        // later (createOrUpdatePortalUser()) could silently hijack that
        // other account. Checked even when portal access isn't being enabled
        // right now, since the email can still be reused for that later.
        if (!empty($data['email']) && $this->emailBelongsToAnotherAccount($data['email'])) {
            return ApiResponse::error('This email is already registered as a staff or Company Admin account.', 422);
        }

        // Same company-scoped duplicate guard as Api\User\ClientController.
        if (!empty($data['email']) && Client::where('company_id', $companyId)->where('email', $data['email'])->exists()) {
            return ApiResponse::error('Client with this email already exists.', 422);
        }

        // Portal module check only needed when enabling portal access
        if (!empty($data['enable_portal']) && !$this->hasPortalModule($companyId)) {
            return ApiResponse::error('Client portal module is not enabled for this company.', 403);
        }

        // No seat limit on Clients — that cap applies to staff Users only
        // (see Admin\UserController::orgUserLimit()), never to client-portal
        // logins. seatInfo() below is informational display only now.
        if (!empty($data['enable_portal'])) {
            // A Company Admin or non-client staff email must never become a
            // portal-login User row — checked before the Client row itself
            // is created so nothing is left half-created.
            if (!empty($data['portal_email']) && $this->emailBelongsToAnotherAccount($data['portal_email'])) {
                return ApiResponse::error('This email is already registered as a staff or Company Admin account.', 422);
            }
        }

        $client = Client::create([
            'company_id'    => $companyId,
            'name'          => $data['name'],
            'email'         => $data['email'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'company_name'  => $data['company_name'] ?? null,
            'address'       => $data['address'] ?? null,
            'country'       => $data['country'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'status'        => $data['status'] ?? 'active',
            'portal_access' => false,
            'created_by_admin_id' => $this->admin()->id,
        ]);

        $this->seedPermissions($client->id);

        if (!empty($data['enable_portal']) && !empty($data['portal_email'])) {
            $error = $this->createOrUpdatePortalUser($client, $data['portal_email'], $data['portal_password']);
            if ($error) {
                return ApiResponse::error($error, 422);
            }

            // Portal login details only ever go out when Portal Access is
            // actually enabled at creation — a client created without it
            // gets no email at all. Non-blocking: a mail failure must never
            // fail the client creation itself (same pattern as PublicController::register()'s WelcomeMail).
            try {
                Mail::to($data['portal_email'])->send(new ClientPortalWelcomeMail(
                    $client, $client->company ?? Company::find($companyId), $data['portal_email'], $data['portal_password']
                ));
            } catch (\Throwable) {
                // Don't fail client creation if mail fails
            }
        }

        $client->load(['user:id,email,is_active', 'company:id,name']);

        return ApiResponse::success($client, 'Client created successfully');
    }

    // =========================================================================
    // PUT /api/admin/clients/{id}
    // =========================================================================
    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $data = $request->validate([
            'name'         => 'sometimes|required|string|max:150',
            'email'        => 'nullable|email|max:255',
            'phone'        => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'company_name' => 'nullable|string|max:150',
            'address'      => 'nullable|string|max:500',
            'country'      => 'nullable|string|max:5',
            'notes'        => 'nullable|string|max:1000',
            'status'       => 'nullable|in:active,inactive,blocked',
        ]);

        // Same guard as store() — see emailBelongsToAnotherAccount().
        if (!empty($data['email']) && $data['email'] !== $client->email && $this->emailBelongsToAnotherAccount($data['email'])) {
            return ApiResponse::error('This email is already registered as a staff or Company Admin account.', 422);
        }

        // The contact email IS the portal login email — the same rule
        // Api\Client\ProfileController applies when the client edits their own
        // details. Without this the two drift apart the moment an admin edits
        // the contact address here: the Portal Login tab would keep showing the
        // old login (and, since that field is not editable there, offer no way
        // to fix it), and the client would be signing in with an address
        // nothing on screen still mentions.
        //
        // Only for a client who actually has a portal login; a client with no
        // user_id has no credential to move.
        $portalUser = $client->user_id ? User::find($client->user_id) : null;
        $loginEmailChanging = $portalUser
            && !empty($data['email'])
            && strtolower($data['email']) !== strtolower((string) $portalUser->email);

        if ($loginEmailChanging && User::where('email', $data['email'])->where('id', '!=', $portalUser->id)->exists()) {
            return ApiResponse::error('This email is already registered to another account.', 422);
        }

        // One transaction — the contact and login addresses must never be left
        // disagreeing with each other.
        DB::transaction(function () use ($client, $data, $portalUser, $loginEmailChanging) {
            $client->update($data);

            if ($loginEmailChanging) {
                $portalUser->update(['email' => $data['email']]);
            }
        });

        $client->load(['user:id,email,is_active', 'company:id,name']);

        return ApiResponse::success(
            $client,
            $loginEmailChanging
                ? 'Client updated. Their portal login email changed to ' . $data['email'] . '.'
                : 'Client updated'
        );
    }

    // =========================================================================
    // DELETE /api/admin/clients/{id}
    // =========================================================================
    // Soft delete (Client uses SoftDeletes — deleted_at, not a real row
    // removal) — deliberately NOT a hard delete: invoices.client_id and
    // projects.client_id both reference clients, and invoices specifically
    // CASCADEs on an actual SQL DELETE (see 2026_06_27_081949_make_client_id_
    // nullable_on_invoices.php), which would wipe the client's entire billing
    // history. A soft delete only ever UPDATEs deleted_at, so that FK cascade
    // never fires — the client just stops showing up in normal queries
    // (Eloquent's default SoftDeletes scope) while every invoice/project
    // record stays intact. Also deactivates the linked portal login (if any)
    // so a "deleted" client can't still sign in to the Client Portal.
    // =========================================================================
    // GET /api/admin/clients/{id}/delete-summary
    //
    // Everything a delete would take, so the confirmation modal can spell out
    // the blast radius before the admin commits to it. Read-only.
    // =========================================================================
    public function deleteSummary(int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);

        return ApiResponse::success(ClientDeletionService::summary($client));
    }

    // Permanent, not a soft delete: the client and every project, task,
    // invoice, payment and compliance record hanging off it are removed for
    // good. The UI must show delete-summary and take an explicit confirmation
    // first — there is nothing to restore afterwards.
    public function destroy(int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);

        ClientDeletionService::purge($client);

        return ApiResponse::success(null, 'Client and all linked records permanently deleted');
    }

    // =========================================================================
    // PUT /api/admin/clients/{id}/permissions
    // =========================================================================
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'boolean',
        ]);

        $allowed = array_keys(ClientPortalPermission::MODULES);

        foreach ($request->permissions as $moduleKey => $isEnabled) {
            if (!in_array($moduleKey, $allowed)) continue;
            ClientPortalPermission::updateOrCreate(
                ['client_id' => $client->id, 'module_key' => $moduleKey],
                ['is_enabled' => (bool) $isEnabled]
            );
        }

        return ApiResponse::success(null, 'Permissions updated');
    }

    // =========================================================================
    // POST /api/admin/clients/{id}/enable-portal
    // =========================================================================
    public function enablePortal(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'portal_email'    => 'nullable|email|max:255',
            'portal_password' => 'nullable|string|min:6|max:100',
        ]);

        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $existingUser = $client->user_id ? User::find($client->user_id) : null;

        if ($existingUser && !$request->filled('portal_password')) {
            if ($existingUser->role_type !== 'client') {
                return ApiResponse::error('This linked login is not a client account.', 422);
            }

            if ($request->filled('portal_email') && $request->portal_email !== $existingUser->email) {
                if ($this->emailBelongsToAnotherAccount($request->portal_email)) {
                    return ApiResponse::error('This email is already registered as a staff or Company Admin account.', 422);
                }
                $existingUser->email = $request->portal_email;
            }

            $existingUser->is_active = true;
            $existingUser->save();
            $client->update(['portal_access' => true]);
            $this->seedPermissions($client->id);

            return ApiResponse::success([
                'client_id'    => $client->id,
                'portal_email' => $existingUser->email,
            ], 'Portal access enabled');
        }

        if (!$request->filled('portal_email') || !$request->filled('portal_password')) {
            return ApiResponse::error('Portal email and password are required for clients without an existing login.', 422);
        }

        // A Company Admin or non-client staff email must never become a
        // portal-login User row — see the matching check in store().
        if ($this->emailBelongsToAnotherAccount($request->portal_email)) {
            return ApiResponse::error('This email is already registered as a staff or Company Admin account.', 422);
        }

        // No seat limit on Clients — see the matching note in store().

        $error = $this->createOrUpdatePortalUser($client, $request->portal_email, $request->portal_password);
        if ($error) {
            return ApiResponse::error($error, 422);
        }
        $this->seedPermissions($client->id);

        // Same login-details email as store()'s enable_portal path — a
        // client whose portal is enabled later (not at creation) still needs
        // their credentials. Non-blocking: never fails this action.
        try {
            Mail::to($request->portal_email)->send(new ClientPortalWelcomeMail(
                $client, $client->company ?? Company::find($client->company_id), $request->portal_email, $request->portal_password
            ));
        } catch (\Throwable) {
            // Don't fail portal enablement if mail fails
        }

        return ApiResponse::success([
            'client_id'    => $client->id,
            'portal_email' => $request->portal_email,
        ], 'Portal access enabled');
    }

    // =========================================================================
    // POST /api/admin/clients/{id}/disable-portal
    // =========================================================================
    public function disablePortal(int $id): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $client->update(['portal_access' => false]);

        // Every Api\Client\* endpoint re-checks portal_access per request via
        // ScopesToPermittedClient, so a NEW request is already cut off — but
        // without this, a tab the client already has open keeps working
        // until its token happens to expire, since Sanctum tokens aren't
        // otherwise time-limited. "Disable" should mean disabled now.
        $client->user?->tokens()->delete();

        return ApiResponse::success(null, 'Portal access disabled');
    }
}
