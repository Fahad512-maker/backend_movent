<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment reminder raised against an invoice — Finance > Invoice Reminders.
 *
 * The table is the original 2024_01_01_000015 one, expanded rather than
 * replaced (see 2026_09_21_100100_expand_invoice_reminders_for_finance). It
 * was never written to before, so nothing here has to preserve old behaviour;
 * what it does have to preserve is the shape other code already expects —
 * Invoice::reminders(), and the invoice_id/type/message/sent_at/sent_by
 * columns, all unchanged.
 *
 * Lifecycle: scheduled → sent | failed, or → cancelled. A reminder is a
 * record of intent first and a delivery second, which is why `status` and
 * `reminder_date` exist independently of `sent_at`.
 */
class InvoiceReminder extends Model
{
    protected $fillable = [
        'invoice_id', 'company_id', 'created_by', 'created_by_admin_id',
        'sent_by', 'sent_by_admin_id',
        'type', 'reminder_date', 'status', 'subject', 'message',
        'sent_at', 'sent_to', 'failure_reason',
    ];

    protected $casts = [
        'reminder_date' => 'date',
        'sent_at'       => 'datetime',
    ];

    public const STATUSES = ['scheduled', 'sent', 'failed', 'cancelled'];

    // email is the only channel that actually delivers today — sms/whatsapp
    // are original enum values kept so the column never rejects a historical
    // row, and are refused at validation time rather than silently accepted
    // and never sent. See App\Services\Finance\InvoiceReminderService.
    public const TYPES = ['email', 'sms', 'whatsapp'];

    public const DELIVERABLE_TYPES = ['email'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function sentByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'sent_by_admin_id');
    }

    /** Whichever guard raised it — see Payment::reconciledByName() for the same two-guard pattern. */
    public function createdByName(): ?string
    {
        if ($this->created_by) {
            return ($this->relationLoaded('createdBy') ? $this->createdBy : $this->createdBy()->first())?->name;
        }

        if ($this->created_by_admin_id) {
            return ($this->relationLoaded('createdByAdmin') ? $this->createdByAdmin : $this->createdByAdmin()->first())?->name;
        }

        return null;
    }

    /** Null until it is actually sent, and null for one the scheduler sent unattended. */
    public function sentByName(): ?string
    {
        if ($this->sent_by) {
            return ($this->relationLoaded('sentBy') ? $this->sentBy : $this->sentBy()->first())?->name;
        }

        if ($this->sent_by_admin_id) {
            return ($this->relationLoaded('sentByAdmin') ? $this->sentByAdmin : $this->sentByAdmin()->first())?->name;
        }

        return null;
    }

    /**
     * Ready to go out: still scheduled, and its date has arrived.
     *
     * A reminder with no date is manual-send only and never picked up by the
     * scheduler — leaving the date blank is how a user says "I will send this
     * myself".
     */
    public function scopeDue($query, ?string $onOrBefore = null)
    {
        return $query->where('status', 'scheduled')
            ->whereNotNull('reminder_date')
            ->whereDate('reminder_date', '<=', $onOrBefore ?? now()->toDateString())
            // A suspended/deactivated company (Company::isOperational()) gets
            // no new scheduled business actions sent on its behalf — the
            // reminder itself is untouched (still 'scheduled', still due
            // whenever the company is reactivated; invoices:send-due-reminders
            // is this scope's only caller, so nothing else stops seeing it).
            ->whereHas('company', fn ($q) => $q->operational());
    }
}
