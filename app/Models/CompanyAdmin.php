<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class CompanyAdmin extends Authenticatable
{
    use HasApiTokens;

    /**
     * What a deactivated tenant owner is told, wherever they are turned away.
     *
     * is_active is the Super Admin's own switch over a whole tenant
     * (Api\SuperAdmin\CompanyAdminController::toggleStatus()) and is NOT the
     * subscription lifecycle — a deactivated admin cannot pay their way back
     * in, only the Super Admin can let them back in. Shared by every refusal
     * path so they can never drift: password login
     * (Auth\AdminAuthController::tryCredentials()), Google login
     * (Auth\AdminGoogleAuthController, via DEACTIVATED_ERROR_CODE below) and
     * every authenticated request (Http\Middleware\EnsureTenantActive).
     */
    public const DEACTIVATED_MESSAGE = 'Your account has been deactivated by the Super Admin. Please contact the administrator for assistance.';

    /**
     * The error_code that rides along with DEACTIVATED_MESSAGE, so the
     * frontend can tell this apart from an ordinary permission denial — this
     * one has no in-app resolution, so it ends the session rather than just
     * showing a toast (see frontend/lib/axios.ts).
     *
     * Deliberately NOT the existing 'inactive_account' code: that one is
     * shared with deactivated STAFF accounts (Auth\GoogleAuthController),
     * who are switched off by their own Company Admin and must not be told
     * to contact the Super Admin.
     */
    public const DEACTIVATED_ERROR_CODE = 'account_deactivated';

    /**
     * The same switch, as the tenant's STAFF experience it.
     *
     * Deactivation takes down the whole tenant, so a company's users are
     * turned away too — but it is not THEIR account that was deactivated, and
     * the Super Admin is not who they deal with. They are told what actually
     * happened and pointed at their own administrator.
     */
    public const TENANT_DEACTIVATED_MESSAGE = 'Your company account has been deactivated by the Super Admin. Please contact your administrator for assistance.';

    public const TENANT_DEACTIVATED_ERROR_CODE = 'company_deactivated';

    protected $fillable = [
        'package_id', 'is_custom_selection', 'name', 'email', 'password', 'google_id', 'phone', 'avatar_path',
        'subscription_status', 'trial_ends_at', 'subscription_ends_at',
        'grace_period_ends_at', 'suspended_at', 'suspended_reason', 'access_override',
        'is_active', 'last_login_at', 'max_users_per_company', 'max_companies',
        'notifications_last_read_at', 'tasks_last_read_at', 'projects_last_read_at', 'read_audit_log_ids',
        // Tenant-wide business/invoicing identity (Settings' Company/Invoice/
        // Bank tabs) — distinct from name/email/phone above, which are this
        // admin's own login identity, and from companies.name, which still
        // identifies individual companies for internal management.
        'business_name', 'industry', 'business_email', 'business_phone', 'address',
        'timezone', 'currency', 'logo_path',
        'invoice_prefix', 'invoice_tax_rate', 'invoice_payment_terms', 'invoice_notes',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_iban', 'bank_swift',
    ];

    protected $hidden = ['password', 'remember_token'];

    // Surfaced to the Super Admin company-admin list/detail screens so
    // "is this tenant actually blocked right now" never has to be
    // re-derived client-side from subscription_status + date math — the raw
    // column can be stale (e.g. still 'trial' until the lifecycle cron
    // formalizes an expired trial to 'trial_expired'), but access is always
    // decided by the live computation these mirror.
    protected $appends = ['effective_subscription_status', 'is_blocked_from_access'];

    public function getEffectiveSubscriptionStatusAttribute(): string
    {
        return $this->effectiveSubscriptionStatus();
    }

    public function getIsBlockedFromAccessAttribute(): bool
    {
        return $this->isBlockedFromAccess();
    }

    protected $casts = [
        'trial_ends_at'        => 'datetime',
        'subscription_ends_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'suspended_at'         => 'datetime',
        'last_login_at'        => 'datetime',
        'notifications_last_read_at' => 'datetime',
        'tasks_last_read_at'   => 'datetime',
        'projects_last_read_at' => 'datetime',
        'read_audit_log_ids'   => 'array',
        'is_active'            => 'boolean',
        'access_override'      => 'boolean',
        'is_custom_selection'  => 'boolean',
        'password'             => 'hashed',
        'invoice_tax_rate'     => 'float',
        'invoice_payment_terms'=> 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    // Discount % this admin's CURRENT billing term carries (0 for monthly)
    // — applied to any "buy more" purchase (modules, seats, companies) so an
    // admin already paying for a discounted multi-year term gets that same
    // discount on add-ons instead of paying the full undiscounted catalog
    // price. See ModulePurchaseController::purchase(), SeatPurchaseController::purchase().
    public function addOnDiscountPercent(): float
    {
        return (float) ($this->package?->discount_percent ?? 0);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'admin_id');
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'admin_id');
    }

    // Tenant-level payment gateways — shared across every company under this
    // Company Admin account (see CompanyPaymentGateway::resolveActiveGateways()).
    public function paymentGateways(): HasMany
    {
        return $this->hasMany(CompanyPaymentGateway::class, 'company_admin_id');
    }

    // subscription_status only actually flips (trial → trial_expired/
    // grace_period, active → grace_period/suspended) when the daily
    // `subscription:check-lifecycle` command runs — on an environment with no
    // real OS scheduler wired up (or if that cron is simply late/down for a
    // day), the stored column can sit stale past its real expiry date. This
    // derives what the batch job WOULD have set by now from the same
    // Subscription Policy it uses — the single source of truth every access
    // check (isBlockedFromAccess()) AND every display (AdminResource,
    // ClientController::usage()) should read instead of the raw column, so
    // both agree with each other and neither waits for the next cron tick.
    // The batch job still runs daily to formalize the stored status, fire
    // notifications, and disable modules (see disableAllModules()) — this
    // only closes the gap for whatever lands before it does.
    public function effectiveSubscriptionStatus(): string
    {
        // The column allows NULL at the DB level (its DEFAULT 'trial' only
        // applies when the column is omitted from an INSERT entirely, not
        // when something explicitly writes null) — this return type doesn't,
        // so guard here rather than relying on every write path to always
        // set it. Same fallback the column's own DEFAULT uses.
        if ($this->subscription_status === null) {
            return 'trial';
        }

        $policy = SubscriptionPolicy::current();

        if ($this->subscription_status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isPast()) {
            if ($policy->trial_expiry_behavior === 'grace_period' && $policy->grace_period_days > 0) {
                return $this->trial_ends_at->copy()->addDays($policy->grace_period_days)->isPast()
                    ? 'suspended'
                    : 'grace_period';
            }
            return $policy->auto_suspend_on_trial_expiry ? 'suspended' : 'trial_expired';
        }

        if ($this->subscription_status === 'grace_period' && $this->grace_period_ends_at && $this->grace_period_ends_at->isPast()) {
            return 'suspended';
        }

        if ($this->subscription_status === 'active' && $this->subscription_ends_at && $this->subscription_ends_at->isPast()) {
            if ($policy->renewal_failure_behavior === 'grace_period' && $policy->grace_period_days > 0) {
                return $this->subscription_ends_at->copy()->addDays($policy->grace_period_days)->isPast()
                    ? 'suspended'
                    : 'grace_period';
            }
            return $policy->auto_suspend_on_renewal_failure ? 'suspended' : 'active';
        }

        return $this->subscription_status;
    }

    // Statuses where every Company + user under this tenant must be refused
    // CRM access (see EnsureSubscriptionActive) — 'grace_period' deliberately
    // NOT included here, since that status still allows full access while it
    // counts down (a warning banner, not a block).
    //
    // access_override is Super Admin's manual "let them in anyway" switch
    // (Api\SuperAdmin\CompanyAdminController::toggleAccessOverride()) — it
    // short-circuits the block WITHOUT touching subscription_status, so an
    // expired/suspended account stays honestly expired/suspended for
    // billing purposes while still being usable. Never set this any other
    // way; if the tenant has genuinely paid, update subscription_status
    // instead (see SubscriptionPaymentController) and leave this false.
    public function isBlockedFromAccess(): bool
    {
        if ($this->access_override) {
            return false;
        }

        return in_array($this->effectiveSubscriptionStatus(), ['trial_expired', 'suspended', 'cancelled', 'pending_payment'], true);
    }

    public function trialDaysRemaining(): int
    {
        if (!$this->trial_ends_at) {
            return 0;
        }
        return max(0, (int) now()->startOfDay()->diffInDays($this->trial_ends_at->copy()->startOfDay(), false));
    }

    // The grace period's end date, whether or not the cron has formalized it
    // onto grace_period_ends_at yet — derived live from whichever date
    // (trial_ends_at or subscription_ends_at) plus the policy's grace window
    // actually put this account into an effective 'grace_period' state. Null
    // whenever effectiveSubscriptionStatus() isn't 'grace_period'.
    public function effectiveGracePeriodEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->effectiveSubscriptionStatus() !== 'grace_period') {
            return null;
        }

        if ($this->grace_period_ends_at) {
            return $this->grace_period_ends_at;
        }

        $policy = SubscriptionPolicy::current();

        if ($this->subscription_status === 'trial' && $this->trial_ends_at) {
            return $this->trial_ends_at->copy()->addDays($policy->grace_period_days);
        }

        if ($this->subscription_status === 'active' && $this->subscription_ends_at) {
            return $this->subscription_ends_at->copy()->addDays($policy->grace_period_days);
        }

        return null;
    }

    public function graceDaysRemaining(): int
    {
        $end = $this->effectiveGracePeriodEndsAt();
        if (!$end) {
            return 0;
        }
        return max(0, (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false));
    }

    // Flips every company_modules row to disabled (never deletes — "no data
    // deletion" is a hard rule of the subscription policy) the moment this
    // account becomes isBlockedFromAccess()-true, so the sidebar/module list
    // actually reflects "nothing available" instead of a middleware-only
    // block with the full trial catalog still showing underneath. Called from
    // CheckSubscriptionLifecycle (auto transitions) and CompanyAdminController
    // (Super Admin manual status override).
    public function disableAllModules(): void
    {
        foreach ($this->companies as $company) {
            $company->modules()->update(['is_enabled' => false]);
        }
    }

    // Counterpart to disableAllModules() — re-enables whatever module rows
    // already exist (suspension only ever disabled them, never deleted), for
    // a reactivation that isn't already handled by a more specific sync (see
    // SubscriptionPaymentController::process()'s custom-selection and
    // trial-to-package-trim branches, which fully replace the module set
    // instead of just flipping the flag back).
    public function enableExistingModules(): void
    {
        foreach ($this->companies as $company) {
            $company->modules()->update(['is_enabled' => true]);
        }
    }
}
