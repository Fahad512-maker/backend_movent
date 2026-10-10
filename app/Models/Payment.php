<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id', 'recorded_by', 'recorded_by_admin_id', 'receipt_number', 'amount', 'currency', 'converted_amount',
        'converted_currency', 'exchange_rate', 'method', 'gateway', 'company_gateway_id',
        'gateway_ref', 'gateway_session_id', 'gateway_mode', 'status', 'payment_date', 'notes',
        // Finance reconciliation — deliberately separate from `status`; see
        // the 2026_09_21_100000_add_reconciliation_to_payments_table migration
        // for why. Only ever written through
        // App\Services\Finance\PaymentReconciliationService.
        'reconciliation_status', 'reconciled_at', 'reconciled_by', 'reconciled_by_admin_id',
        'reconciliation_note',
    ];

    protected $casts = [
        'amount'            => 'decimal:2',
        'converted_amount'  => 'decimal:2',
        'exchange_rate'     => 'decimal:8',
        'payment_date'      => 'date',
        'reconciled_at'     => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function companyGateway(): BelongsTo
    {
        return $this->belongsTo(CompanyPaymentGateway::class, 'company_gateway_id');
    }

    // ── Reconciliation ──────────────────────────────────────────────────────

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function reconciledByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'reconciled_by_admin_id');
    }

    public function isReconciled(): bool
    {
        return $this->reconciliation_status === 'reconciled';
    }

    /**
     * WHO reconciled this, whichever guard they signed in under.
     *
     * Staff are `users` rows and a Company Admin is a `company_admins` row, so
     * exactly one of the two columns is ever set. Every Finance surface reads
     * this instead of picking a column, so the answer never differs between
     * the payments list, the detail drawer and the CSV export.
     */
    public function reconciledByName(): ?string
    {
        if ($this->reconciled_by) {
            return ($this->relationLoaded('reconciledBy') ? $this->reconciledBy : $this->reconciledBy()->first())?->name;
        }

        if ($this->reconciled_by_admin_id) {
            return ($this->relationLoaded('reconciledByAdmin') ? $this->reconciledByAdmin : $this->reconciledByAdmin()->first())?->name;
        }

        return null;
    }

    /**
     * WHO recorded this payment — the mirror of reconciledByName() for the
     * other end of a payment's life.
     *
     * Returns null for a payment nobody recorded by hand: a gateway charge,
     * a webhook, or a claim the customer submitted through the public link
     * all leave recorded_by null, and "System / Gateway" is the caller's
     * label to apply, not this model's.
     */
    public function recordedByName(): ?string
    {
        if ($this->recorded_by) {
            return ($this->relationLoaded('recordedBy') ? $this->recordedBy : $this->recordedBy()->first())?->name;
        }

        if ($this->recorded_by_admin_id) {
            return CompanyAdmin::find($this->recorded_by_admin_id)?->name;
        }

        return null;
    }
}
