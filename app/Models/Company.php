<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'admin_id', 'name', 'industry', 'email', 'phone', 'address',
        'timezone', 'currency', 'country', 'logo_path', 'storage_folder', 'is_active',
        'suspended_at', 'suspension_reason',
        'invoice_prefix', 'invoice_tax_rate', 'invoice_payment_terms', 'invoice_notes',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_iban', 'bank_swift',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'suspended_at'         => 'datetime',
        'invoice_tax_rate'     => 'float',
        'invoice_payment_terms'=> 'integer',
    ];

    // Manually suspended by its own Company Admin (distinct from is_active,
    // Super Admin's own toggle — see SuperAdmin\CompanyController::
    // toggleStatus()) — the two compose in isOperational() below rather than
    // sharing one column, so reactivating one can never lift the other.
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    // Whether this company should be usable for normal business operations
    // right now, independent of its tenant's own subscription/billing status
    // (see CompanyAdmin::isBlockedFromAccess(), checked separately). False
    // when either a Super Admin has deactivated it OR its own Company Admin
    // has manually suspended it.
    public function isOperational(): bool
    {
        return $this->is_active && !$this->isSuspended();
    }

    public function scopeOperational($query)
    {
        return $query->where('is_active', true)->whereNull('suspended_at');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'admin_id');
    }

    /**
     * The business identity/invoice defaults/bank details shown to clients
     * (invoice PDFs, emails, the public payment page).
     *
     * PER-COMPANY as of 2026-09-11: this company's OWN columns are
     * authoritative, and the tenant-level company_admins columns are only a
     * fallback for a field this company has never had set. It was the other
     * way around until now — one shared configuration for every company an
     * admin owns — which meant editing Settings while Company A was selected
     * silently rewrote Company B's invoice prefix, bank details and business
     * identity too. Settings' Company/Invoice/Bank tabs now read and write
     * the selected company's row (see Api\Admin\SettingsController and
     * Api\User\SettingsController), so Company A → Settings A and
     * Company B → Settings B are genuinely independent.
     *
     * The fallback is what keeps existing tenants working: the
     * 2026_09_11_100000_backfill_per_company_settings migration copied each
     * tenant's then-effective profile into every one of its company rows, so
     * nothing changed the day the precedence flipped; the fallback only ever
     * fires for a company row written before that migration existed.
     */
    public function invoicingProfile(): array
    {
        $admin = $this->admin;

        return [
            // companies.name is this company's own identity and always wins —
            // business_name is the tenant-wide leftover, used only if this
            // row somehow has no name at all.
            'name'                  => $this->name                     ?: ($admin?->business_name ?: $admin?->name),
            'logo_path'             => $this->logo_path                 ?: $admin?->logo_path,
            // currency was ALREADY per-company before this flip (one admin
            // can own a PKR company and a USD company) — it is the field the
            // rest of this method has now been brought in line with. See the
            // Api\Admin\ClientController::updateCompany() note about the bug
            // where editing one company's currency leaked into every other.
            'currency'              => $this->currency ?: ($admin?->currency ?: 'USD'),
            'invoice_prefix'        => $this->invoice_prefix            ?: ($admin?->invoice_prefix ?: 'INV'),
            'invoice_tax_rate'      => $this->invoice_tax_rate          ?? ($admin?->invoice_tax_rate ?? 0),
            'invoice_payment_terms' => $this->invoice_payment_terms     ?? ($admin?->invoice_payment_terms ?? 30),
            'invoice_notes'         => $this->invoice_notes             ?: $admin?->invoice_notes,
            'bank_name'             => $this->bank_name                 ?: $admin?->bank_name,
            'bank_account_name'     => $this->bank_account_name         ?: $admin?->bank_account_name,
            'bank_account_number'   => $this->bank_account_number       ?: $admin?->bank_account_number,
            'bank_iban'             => $this->bank_iban                 ?: $admin?->bank_iban,
            'bank_swift'            => $this->bank_swift                ?: $admin?->bank_swift,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    // Top-level catalog keys (e.g. 'hr') this company owns — "every
    // sub_module present" rule shared by every caller that needs to roll
    // granular company_modules rows back up to the catalog's top-level keys.
    // $onlyEnabled=true (default) is live access, what's actually usable
    // right now; pass false to read what was SELECTED regardless of
    // is_enabled — disableAllModules()/suspension only ever flips the flag
    // off, it never deletes the rows (see CompanyAdmin::disableAllModules()),
    // so false is what a reactivation/renewal screen needs to still show a
    // suspended admin's prior pick.
    public function catalogKeys(bool $onlyEnabled = true): array
    {
        $query = $this->modules();
        if ($onlyEnabled) {
            $query->where('is_enabled', true);
        }
        $granular = $query->pluck('module_key')->toArray();

        return Module::where('is_active', true)->get(['key', 'sub_modules'])
            ->filter(fn ($m) => !empty($m->sub_modules) && empty(array_diff($m->sub_modules, $granular)))
            ->pluck('key')->all();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function chatThreads(): HasMany
    {
        return $this->hasMany(ChatThread::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function compliancePolicies(): HasMany
    {
        return $this->hasMany(CompliancePolicy::class);
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function complianceIncidents(): HasMany
    {
        return $this->hasMany(ComplianceIncident::class);
    }

    public function complianceViolations(): HasMany
    {
        return $this->hasMany(ComplianceViolation::class);
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    }

    public function systemAuditLogs(): HasMany
    {
        return $this->hasMany(SystemAuditLog::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function recruitments(): HasMany
    {
        return $this->hasMany(Recruitment::class);
    }
}
