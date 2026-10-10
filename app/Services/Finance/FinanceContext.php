<?php

namespace App\Services\Finance;

use App\Models\CompanyAdmin;
use App\Models\CompanyModule;
use App\Models\CompanyUserAssignment;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Support\ActiveCompany;
use App\Support\PermissionDebug;
use Illuminate\Http\Request;

/**
 * WHO is asking, WHICH companies they may touch, and WHAT they may do —
 * resolved once per Finance request, for either guard.
 *
 * The Finance Area is served to two very different callers over the same
 * controllers: a Company Admin on the `admin` guard (a company_admins row,
 * owns companies, bypasses every permission check in this app) and a staff
 * member on `sanctum` (a users row, scoped to one active company, gated on
 * user_company_permissions). Every other module in this codebase solves that
 * by shipping two near-identical controllers — Api\Admin\XController and
 * Api\User\XController — which is how the invoice/payment logic came to live
 * in two places that have since drifted. Finance resolves the difference here
 * instead, once, so there is exactly one implementation of each screen.
 *
 * The three questions it answers, and why each is the whole safety story:
 *
 *   companyIds()  Every Finance query is filtered by this and nothing else
 *                 decides tenancy. For a staff member it is a single company,
 *                 proven against an ACTIVE company_user_assignments row (via
 *                 Support\ActiveCompany, the same resolver CheckCompanyModule
 *                 uses) — never the X-Active-Company-Id header on its own and
 *                 never the users.company_id column on its own. For a Company
 *                 Admin it is intersected with the companies they own. A
 *                 forged id therefore resolves to a company they hold, or to
 *                 nothing; it can never widen the scope.
 *
 *   can()         user_company_permissions for THIS user in THIS company.
 *                 Always true for the admin guard — that is the established
 *                 rule across the app (the admin guard is itself the gate),
 *                 and it is what makes "Company Admin: full Finance access by
 *                 default" true without seeding a single permission row.
 *
 *   scopeAll()    Whether this caller sees the whole company's money or only
 *                 the records they are tied to. See canViewAllCompanyFinance.
 */
class FinanceContext
{
    public const MODULE = 'finance';

    /**
     * The company_modules key that means "this company has the Finance
     * module" — the same key the routes' outer module:finance_dashboard gate
     * checks (see the Finance Area block in routes/api.php).
     */
    public const ENTITLEMENT_KEY = 'finance_dashboard';

    /** The Invoice module keys Finance accepts as equivalents — see canAny(). */
    private const INVOICE_MODULE = 'invoice';

    private function __construct(
        public readonly string $guard,
        public readonly ?CompanyAdmin $admin,
        public readonly ?User $user,
        /** @var array<int, int> */
        public readonly array $companyIds,
    ) {}

    /**
     * Null when nobody is signed in, or when a staff member has no active
     * company assignment at all. Callers turn that into the project's standard
     * 401/403; there is no "partial" context.
     */
    public static function resolve(Request $request): ?self
    {
        if ($admin = auth('admin')->user()) {
            return new self('admin', $admin, null, self::adminCompanyIds($admin, $request));
        }

        $user = auth('sanctum')->user();
        if ($user instanceof User) {
            // SyncActiveCompanyContext has already overwritten
            // $user->company_id with the resolved active company for this
            // request, but we re-resolve rather than trust that: this class is
            // the tenancy boundary for a module that reads money, and it must
            // hold even if a route is ever registered without that middleware.
            $companyId = ActiveCompany::resolve($request, $user);

            return new self('user', null, $user, $companyId ? [$companyId] : []);
        }

        return null;
    }

    /**
     * A context for unattended work — the daily reminder scheduler.
     *
     * No actor and no permissions: guard 'system' is neither 'admin' (so it
     * never inherits the admin bypass) nor a staff user (so every can() is
     * false). That is exactly right for the scheduler, which acts on
     * reminders a human already created and authorized, and must not be able
     * to do anything a human did not first ask for.
     *
     * $companyId is carried so audit rows land against the right company.
     */
    public static function system(int $companyId): self
    {
        return new self('system', null, null, $companyId ? [$companyId] : []);
    }

    /**
     * Which of the admin's companies this request covers — the Company
     * Selector's choice (Support\ActiveCompany::adminCompanyIds()), narrowed
     * to the companies that actually hold the Finance module.
     *
     * The route's module:finance_dashboard gate only proves that at least ONE
     * company in scope holds Finance; in "All Companies" mode that would
     * otherwise pull in the money of a sibling company that never bought it.
     *
     * @return array<int, int>
     */
    private static function adminCompanyIds(CompanyAdmin $admin, Request $request): array
    {
        $inScope = ActiveCompany::adminCompanyIds($request, $admin);
        if ($inScope === []) {
            return [];
        }

        return CompanyModule::whereIn('company_id', $inScope)
            ->where('module_key', self::ENTITLEMENT_KEY)
            ->where('is_enabled', true)
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isAdmin(): bool
    {
        return $this->guard === 'admin';
    }

    /** True when this context resolved to at least one company it may read. */
    public function hasCompany(): bool
    {
        return $this->companyIds !== [];
    }

    /**
     * The single company a write lands in.
     *
     * Reads may span several companies for a Company Admin who picked "All
     * Companies"; a write never can — it needs one unambiguous owner. Callers
     * that write pass an explicit company_id and validate it with
     * ownsCompany() instead of relying on this.
     */
    public function primaryCompanyId(): int
    {
        return $this->companyIds[0] ?? 0;
    }

    public function ownsCompany(?int $companyId): bool
    {
        return $companyId !== null && in_array((int) $companyId, $this->companyIds, true);
    }

    /**
     * Does this caller hold $permKey in the company being acted on?
     *
     * $companyId lets a per-record check name the record's own company rather
     * than the request's default — which matters for a Company Admin working
     * in "All Companies" mode, where several companies are in scope at once.
     * Omitted, it means the request's primary company.
     */
    public function can(string $permKey, string $moduleKey = self::MODULE, ?int $companyId = null): bool
    {
        if ($this->isAdmin()) {
            // The admin guard IS the authorization for a Company Admin
            // everywhere in this app — but only over companies they own, which
            // companyIds() has already established.
            return $companyId === null || $this->ownsCompany($companyId);
        }

        if (!$this->user) {
            return false;
        }

        $companyId ??= $this->primaryCompanyId();
        if (!$companyId || !$this->ownsCompany($companyId)) {
            return false;
        }

        $result = UserCompanyPermission::where('user_id', $this->user->id)
            ->where('company_id', $companyId)
            ->where('module_key', $moduleKey)
            ->where('permission_key', $permKey)
            ->exists();

        PermissionDebug::log(
            $this->user->id, $companyId, $this->user->role_type, $moduleKey, $permKey, $result
        );

        return $result;
    }

    /**
     * Any one of several (module, permission) pairs.
     *
     * Finance needs this constantly because several capabilities genuinely
     * exist in two modules at once: someone may reach "record a payment" as a
     * Seller through invoice.canRecordPayments or as a Finance user through
     * finance.canRecordPayments, and refusing the first would break the Seller
     * flow that works today.
     *
     * @param array<int, array{0: string, 1: string}> $pairs [moduleKey, permissionKey]
     */
    public function canAny(array $pairs, ?int $companyId = null): bool
    {
        foreach ($pairs as [$moduleKey, $permKey]) {
            if ($this->can($permKey, $moduleKey, $companyId)) {
                return true;
            }
        }

        return false;
    }

    /** Shorthand for "this Finance key, or its Invoice-module equivalent". */
    public function canFinanceOrInvoice(string $financeKey, string $invoiceKey, ?int $companyId = null): bool
    {
        return $this->canAny([
            [self::MODULE, $financeKey],
            [self::INVOICE_MODULE, $invoiceKey],
        ], $companyId);
    }

    /**
     * Does this caller see the whole company's finance data, or only records
     * they are tied to?
     *
     * Company Admin always sees everything. A staff member needs an explicit
     * company-wide grant — either the Finance module's own
     * canViewAllCompanyFinance, or the Invoice module's long-standing
     * canViewAllCompanyInvoices, which already means exactly this for the same
     * rows and which a Lead Manager already holds.
     */
    public function scopeAll(?int $companyId = null): bool
    {
        return $this->canAny([
            [self::MODULE, 'canViewAllCompanyFinance'],
            [self::INVOICE_MODULE, 'canViewAllCompanyInvoices'],
        ], $companyId);
    }

    public function actorUserId(): ?int
    {
        return $this->user?->id;
    }

    public function actorAdminId(): ?int
    {
        return $this->admin?->id;
    }

    public function actorName(): string
    {
        return $this->admin?->name ?? $this->user?->name ?? 'System';
    }

    /**
     * Every company in scope, as id => name, for the Company column and the
     * company filter. Only ever the companies companyIds() already allows.
     *
     * @return array<int, string>
     */
    public function companyNames(): array
    {
        if ($this->companyIds === []) {
            return [];
        }

        return \App\Models\Company::whereIn('id', $this->companyIds)
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /**
     * True when this staff member is still an active member of the company —
     * re-checked on write paths so a suspension takes effect on the very next
     * request rather than at next login.
     */
    public function membershipActive(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (!$this->user || !$this->user->is_active) {
            return false;
        }

        return CompanyUserAssignment::where('user_id', $this->user->id)
            ->whereIn('company_id', $this->companyIds)
            ->where('status', 'active')
            ->exists();
    }
}
