<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = [
        // created_by is a `users` id; created_by_admin_id is set instead when
        // a Company Admin raised the invoice (Admin isn't a `users` row) —
        // together they name whoever the project this invoice starts gets
        // assigned to. See App\Services\PaymentProjectStartService.
        // invoice_type/brand_id decide WHOSE name this invoice goes out under
        // — the company's own, or one of its Brands. See brandingProfile().
        'company_id', 'invoice_type', 'brand_id', 'client_id', 'lead_id', 'project_id', 'project_title', 'project_reference',
        // True for an invoice explicitly created "Without Project" — the one
        // thing App\Services\PaymentProjectStartService checks to skip its
        // auto-create-a-Project-on-payment automation. False (the default)
        // for every existing/legacy invoice, project- or lead-linked or not,
        // so that automation's current behavior is untouched.
        'is_standalone',
        'created_by', 'created_by_admin_id', 'sent_by', 'invoice_number', 'subtotal',
        'tax_rate', 'tax_amount', 'discount_amount', 'total_amount', 'paid_amount',
        'currency', 'status', 'due_date', 'notes', 'sent_at',
        'payment_token', 'token_expires_at',
        'customer_name', 'customer_email', 'customer_phone', 'customer_address',
        // Deal-facing fields — what this invoice is FOR, in terms a client
        // understands, even before a Project exists (see spec §5).
        'invoice_purpose', 'payment_type', 'required_payment_amount', 'counts_toward_project_activation',
        // Optional — set by the Admin/seller when this invoice is explicitly
        // for one of the client's outstanding AI-suggested services. See
        // App\Services\ClientAiServiceBatchService::markTakenIfPaid().
        'ai_service_batch_item_id',
    ];

    // Payment progress travels with every serialised invoice.
    //
    // Appended rather than added to each controller because the requirement is
    // that these figures read the same in the invoice list, the detail screen,
    // the dashboard, the reports, the public share link and the client
    // portal — a dozen call sites, several of which just return the model.
    // Doing it here means a new invoice-facing endpoint gets it for free
    // instead of being the one place that disagrees.
    //
    // Cost: paymentStatus() only touches the payments table in the branch
    // where nothing is confirmed paid, so a list of live invoices adds no
    // queries. List endpoints that want it free either way can eager-load
    // `payments:id,invoice_id,status,created_at` and the accessor uses that.
    protected $appends = ['payment_progress'];

    protected $casts = [
        'subtotal'          => 'decimal:2',
        'tax_rate'          => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'discount_amount'   => 'decimal:2',
        'total_amount'      => 'decimal:2',
        'paid_amount'       => 'decimal:2',
        'due_date'          => 'date',
        'sent_at'           => 'datetime',
        'token_expires_at'  => 'datetime',
        'required_payment_amount'          => 'decimal:2',
        'counts_toward_project_activation' => 'boolean',
        'is_standalone'                    => 'boolean',
    ];

    public function generatePublicToken(?int $expiryDays = null): string
    {
        $token = Str::random(48);

        $this->update([
            'payment_token'    => $token,
            'token_expires_at' => $expiryDays ? now()->addDays($expiryDays) : null,
        ]);

        return $token;
    }

    public function revokePublicToken(): void
    {
        $this->update(['payment_token' => null, 'token_expires_at' => null]);
    }

    public function isTokenValid(): bool
    {
        if (!$this->payment_token) return false;
        if ($this->token_expires_at && $this->token_expires_at->isPast()) return false;
        return true;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Set only on a Brand Invoice — the trading name this one bills under.
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function isBrandInvoice(): bool
    {
        return $this->invoice_type === 'brand' && $this->brand_id !== null;
    }

    // WHOSE identity this invoice is presented under, everywhere it is shown:
    // the detail screen, the PDF, the emails, the share link, the payment page
    // and the client portal all read this rather than the company profile
    // directly, so one invoice can never appear as the company in one place
    // and as a brand in another.
    //
    // Starts from Company::invoicingProfile() and, for a Brand Invoice, lets
    // the brand override the customer-facing identity: name, logo and contact
    // details. Everything else — currency, tax rate, payment terms, bank
    // details, invoice numbering — deliberately stays the company's: a brand
    // is a trading name, not a separate legal or financial entity, and the
    // money still lands in the company's account.
    //
    // A brand that has since been deleted (soft-deleted) falls back to the
    // company, so an old invoice keeps rendering rather than losing its
    // letterhead.
    public function brandingProfile(): array
    {
        $profile = $this->company?->invoicingProfile() ?? [];

        $profile['invoice_type'] = $this->invoice_type ?? 'company';
        $profile['brand']        = null;
        // Brand-only: companies have no website column of their own, so a
        // Company Invoice leaves this null and every surface omits the line.
        $profile['website']      = null;

        if (!$this->isBrandInvoice()) {
            return $profile;
        }

        $brand = $this->relationLoaded('brand') ? $this->brand : $this->brand()->first();
        if (!$brand) {
            return $profile;
        }

        $profile['name']      = $brand->name;
        $profile['logo_path'] = $brand->logo_path ?: $profile['logo_path'] ?? null;
        $profile['email']     = $brand->email;
        $profile['phone']     = $brand->phone;
        $profile['website']   = $brand->website;
        $profile['address']   = $brand->address;
        $profile['country']   = $brand->country;
        $profile['brand']     = [
            'id'      => $brand->id,
            'name'    => $brand->name,
            'email'   => $brand->email,
            'phone'   => $brand->phone,
            'website' => $brand->website,
            'country' => $brand->country,
            'address' => $brand->address,
        ];

        return $profile;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    // Which project this invoice is billed under — opposite direction from
    // Project::invoice() (the project's originating invoice). A project can
    // have many invoices via this column; an invoice belongs to at most one.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function aiServiceBatchItem(): BelongsTo
    {
        return $this->belongsTo(ClientAiServiceBatchItem::class, 'ai_service_batch_item_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ── Payment progress ────────────────────────────────────────────────────
    //
    // How far through paying this invoice the client is, in the one shape every
    // screen reads: the invoice list, the detail page, the public share link,
    // the client portal, the dashboard and the reports. Computed rather than
    // stored so no surface can drift, and so nothing has to be backfilled.
    //
    // Why DERIVED and not a new `status` enum value: `invoices.status` is the
    // invoice LIFECYCLE (draft → sent → paid / overdue / cancelled) and can
    // only hold one value, while "failed" and "refunded" are facts about
    // payment ATTEMPTS that sit alongside it — a refunded invoice may also be
    // past its due date, and a failed card attempt doesn't change the fact
    // that an invoice was sent. Squeezing them into the same column would have
    // meant losing one of the two every time they collide.
    //
    // The states, in the order they are decided (first match wins):
    //   full_paid       settled — whatever the date, whatever happened before
    //   refunded        money arrived and went back; nothing confirmed remains
    //   failed          nothing confirmed, the last attempt failed, not yet due
    //   overdue         a balance remains and the due date has passed
    //   partially_paid  a balance remains, some of it is confirmed paid
    //   pending         nothing confirmed yet and not yet due (includes a
    //                   claim awaiting admin confirmation)
    //
    // draft and cancelled pass straight through instead of being forced into
    // one of the six: an invoice nobody has sent is not "pending payment", and
    // labelling a cancelled invoice "overdue" would be simply wrong.
    public function paymentStatus(): string
    {
        if (in_array($this->status, ['draft', 'cancelled'], true)) {
            return $this->status;
        }

        $total = (float) $this->total_amount;
        $paid  = (float) $this->paid_amount;

        // Half a cent of float drift must not leave an invoice one penny short
        // of "paid" forever.
        if ($total > 0 && $paid >= $total - 0.005) {
            return 'full_paid';
        }

        if ($paid > 0) {
            return $this->isPastDue() ? 'overdue' : 'partially_paid';
        }

        // Nothing confirmed. What happened to the attempts decides the label.
        $attempts = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get(['id', 'status', 'created_at']);

        if ($attempts->contains(fn ($p) => $p->status === 'refunded')) {
            return 'refunded';
        }

        if ($this->isPastDue()) {
            return 'overdue';
        }

        // A pending claim outranks an earlier failure: the client has already
        // tried again and is waiting on the admin.
        if ($attempts->contains(fn ($p) => $p->status === 'pending')) {
            return 'pending';
        }

        $latest = $attempts->sortByDesc('created_at')->first();

        return $latest && $latest->status === 'failed' ? 'failed' : 'pending';
    }

    // Past the due date with money still owed. No due date means never
    // overdue, and a settled invoice is never overdue however late it was.
    public function isPastDue(): bool
    {
        if (!$this->due_date) {
            return false;
        }

        return $this->due_date->endOfDay()->isPast()
            && (float) $this->paid_amount < (float) $this->total_amount - 0.005;
    }

    /**
     * The block every invoice-facing response embeds, so "paid X of Y, Z%
     * left" reads identically wherever it appears.
     *
     * @return array{paid_amount: float, remaining_amount: float, payment_percentage: float, payment_status: string, total_amount: float}
     */
    // Backs the appended `payment_progress` key — see $appends above.
    public function getPaymentProgressAttribute(): array
    {
        return $this->paymentProgress();
    }

    public function paymentProgress(): array
    {
        $total = round((float) $this->total_amount, 2);
        $paid  = round((float) $this->paid_amount, 2);

        return [
            'total_amount'       => $total,
            'paid_amount'        => $paid,
            // Never negative: an over-payment (currency conversion rounding,
            // a client paying twice) shows as nothing left rather than a
            // negative balance the UI would have to special-case.
            'remaining_amount'   => max(0, round($total - $paid, 2)),
            // A zero-total invoice is 100% paid, not a division by zero.
            'payment_percentage' => $total > 0 ? min(100, round($paid / $total * 100, 1)) : 100.0,
            'payment_status'     => $this->paymentStatus(),
        ];
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(InvoiceReminder::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    // The specific gateway account(s) this invoice is restricted to. Empty
    // means no explicit selection was ever made — every read path treats
    // that as "fall back to the tenant's per-type defaults" (today's
    // behavior), so invoices created before this feature keep working.
    public function paymentGatewayAccounts(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyPaymentGateway::class,
            'invoice_payment_gateways',
            'invoice_id',
            'company_gateway_id'
        );
    }
}
