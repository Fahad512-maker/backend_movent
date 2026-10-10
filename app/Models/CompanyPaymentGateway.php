<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyPaymentGateway extends Model
{
    protected $fillable = ['company_admin_id', 'company_id', 'gateway', 'label', 'is_active', 'is_default', 'config'];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        // Secrets at rest — transparently encrypted/decrypted with APP_KEY.
        'config'     => 'encrypted:array',
    ];

    public const GATEWAYS = [
        'paypal'        => 'PayPal',
        'stripe'        => 'Stripe',
        'authorize_net' => 'Authorize.net',
    ];

    // The tenant a row belongs to. Populated on EVERY row, per-company ones
    // included — it is what lets the webhook URLs Company Admins have
    // already pasted into live gateway dashboards
    // (/webhooks/{gateway}/{company_admin_id}) keep resolving after gateways
    // became per-company. See Api\Webhooks\PaymentWebhookController.
    public function companyAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class);
    }

    // Per-company ownership (current model as of 2026-09-11) — a gateway
    // configured here belongs to exactly one company, so changing Company A's
    // Stripe credentials cannot affect Company B's. Null only on the
    // tenant-level rows written between 2026-07-17 and 2026-09-11, which
    // resolveActiveGateways() still honours as a fallback.
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The gateways a given company should offer for invoice payments: that
     * company's OWN rows if it has any, else the tenant-level rows as a
     * fallback for a company created after
     * 2026_09_11_100100_scope_payment_gateways_to_company forked them (its
     * Settings has never been saved, so it has nothing of its own yet).
     *
     * This precedence is the reverse of what it was until 2026-09-11, when
     * tenant rows won and every company under one Company Admin therefore
     * shared one set of gateway accounts. May contain multiple rows of the
     * same gateway type (multi-account) — callers that need exactly one per
     * type should use defaultAccountForType() or filter explicitly.
     */
    public static function resolveActiveGateways(Company $company)
    {
        $companyRows = self::where('company_id', $company->id)
            ->where('is_active', true)
            ->get();

        if ($companyRows->isNotEmpty()) {
            return $companyRows;
        }

        return self::where('company_admin_id', $company->admin_id)
            ->whereNull('company_id')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Every row a company's Settings screen should manage — its own, active or
     * not. resolveActiveGateways() above answers "what can a payer use"; this
     * answers "what does this company's admin see and edit", which must
     * include disabled accounts and must never include another company's.
     *
     * The tenant-level fallback is deliberately NOT part of this: a company
     * with no rows of its own gets an empty Settings tab and adding an
     * account there creates its own, rather than letting one company edit a
     * row every other company is still reading.
     */
    public static function ownedByCompany(int $companyId)
    {
        return self::where('company_id', $companyId)
            ->orderBy('gateway')->orderByDesc('is_default')->orderBy('id')
            ->get();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // The single account a tenant's gateway of this type resolves to when no
    // more specific account was chosen — used by the backward-compatible
    // webhook route and by the legacy per-invoice fallback (invoices with no
    // explicit gateway selection of their own).
    public static function defaultAccountForType(Company $company, string $gateway): ?self
    {
        $rows = self::resolveActiveGateways($company)->where('gateway', $gateway);

        return $rows->firstWhere('is_default', true) ?? $rows->first();
    }
}
