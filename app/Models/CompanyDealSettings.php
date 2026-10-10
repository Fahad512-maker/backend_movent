<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// PER-COMPANY configuration for the Lead-Won -> Deal eligibility -> Project
// creation workflow — one row per company, mirroring CompanyPaymentGateway's
// per-company scoping. Was one shared row per Company Admin account until
// 2026-09-11 (see 2026_09_11_100200_scope_deal_settings_to_company), which
// meant changing the trigger or payment policy for one company changed it for
// every other company that admin owns.
class CompanyDealSettings extends Model
{
    protected $fillable = [
        'company_admin_id', 'company_id', 'project_creation_trigger', 'payment_policy', 'allow_admin_override',
    ];

    protected $casts = [
        'allow_admin_override' => 'boolean',
    ];

    /**
     * The whole Deal Workflow: when does a client's payment create the project?
     * These two are mutually exclusive and are the only options — see
     * 2026_08_11_170000_simplify_company_deal_settings.php for what was removed
     * and why.
     */
    public const TRIGGERS = [
        'full_payment'    => 'After Full Payment — create the project once the invoice is paid in full',
        'partial_payment' => 'After Partial Payment — create the project as soon as any payment is received',
    ];

    /**
     * True when any payment, part or full, is enough to start the project.
     * Read by App\Services\PaymentProjectStartService (auto-creation) and by
     * Api\User\ProjectController::store() (the manual paid-invoice handoff), so
     * both answer the question the same way.
     */
    public function startsOnPartialPayment(): bool
    {
        return $this->project_creation_trigger === 'partial_payment';
    }

    /**
     * The Payment Policy: how much of an invoice a client may settle at once.
     *
     * A DIFFERENT question from TRIGGERS above, which is only about when a
     * payment starts the project. A tenant can legitimately want "the project
     * starts only once the invoice is fully paid" while still accepting a
     * deposit — that combination is the default and is what every existing
     * tenant already has, which is why this is its own field rather than a
     * second meaning bolted onto project_creation_trigger.
     */
    public const PAYMENT_POLICIES = [
        'partial_allowed' => 'Half / Partial Payment Allowed — the client may pay a deposit now and the balance later',
        'full_only'       => 'Full Payment Only — every invoice must be settled in one full payment',
    ];

    /**
     * True when a client may never part-pay: the payment screens offer only
     * the full outstanding amount and refuse anything less.
     *
     * Enforced server-side in Api\PublicInvoiceController::pay() (the one
     * place a client could ever choose an amount) — the client portal has
     * always charged the full outstanding balance regardless.
     */
    public function requiresFullPayment(): bool
    {
        return $this->payment_policy === 'full_only';
    }

    /**
     * The settings behind one invoice, resolved through the company that
     * raised it. Every caller that needs the policy for an invoice goes
     * through this so "no settings row yet" behaves like the defaults.
     */
    public static function forInvoice(Invoice $invoice): ?self
    {
        return $invoice->company_id ? static::forCompany($invoice->company_id) : null;
    }

    public function companyAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * This COMPANY's settings row, or the documented defaults if none has
     * ever been saved — every caller should go through this rather than
     * querying the table directly, so "no row yet" and "explicitly default"
     * behave identically.
     *
     * A company with no row of its own falls back to its tenant's old shared
     * row (company_id IS NULL) before falling back to the defaults, so a
     * company created after 2026_09_11_100200_scope_deal_settings_to_company
     * ran still inherits whatever the admin had configured account-wide
     * instead of silently reverting to 'full_payment'. The returned model in
     * that case is the tenant row itself and is NOT saved by this method —
     * writes always go through the per-company row (see
     * Api\Admin\SettingsController::updateDealWorkflowSettings()).
     */
    public static function forCompany(int $companyId): self
    {
        $own = static::where('company_id', $companyId)->first();
        if ($own) {
            return $own;
        }

        $adminId = Company::find($companyId)?->admin_id;

        $tenant = $adminId
            ? static::where('company_admin_id', $adminId)->whereNull('company_id')->first()
            : null;

        if ($tenant) {
            return $tenant;
        }

        return new static([
            'company_admin_id' => $adminId,
            'company_id'       => $companyId,
            // The safer of the two: nothing starts until the invoice is settled.
            'project_creation_trigger' => 'full_payment',
            'payment_policy'           => 'partial_allowed',
            'allow_admin_override'     => true,
        ]);
    }
}
