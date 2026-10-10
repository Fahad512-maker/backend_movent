<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Where the Client Portal's Payment Assistant has got to, for one portal login.
 *
 * Server-side state on purpose: nothing the browser sends decides which company,
 * which invoice, or how much. Each request re-reads this row and re-validates
 * the invoice against user + company before doing anything.
 */
class PaymentAssistantSession extends Model
{
    /**
     * The flow, in order. Only these values are ever written.
     *
     * There is no "waiting_for_payment_method" step: the tenant gateway
     * abstraction (PaymentGateways\GatewayContract) is hosted-checkout only, so
     * a card is entered on the gateway's own page and never reaches this app.
     * CONFIRMING is therefore the last stop before handing off.
     */
    public const COMPANY_SELECTION = 'company_selection';
    public const WAITING_FOR_INVOICE = 'waiting_for_invoice';
    public const INVOICE_FOUND = 'invoice_found';
    public const CONFIRMING = 'confirming';
    public const PROCESSING = 'processing';
    public const PAYMENT_SUCCESS = 'payment_success';
    public const PAYMENT_FAILED = 'payment_failed';

    protected $fillable = [
        'user_id', 'company_id', 'client_id', 'invoice_id', 'state',
        'conversation_id', 'conversation_started_at',
    ];

    protected $casts = ['conversation_started_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Back to "which invoice?", keeping the chosen company. */
    public function clearInvoice(): void
    {
        $this->update(['invoice_id' => null, 'state' => self::WAITING_FOR_INVOICE]);
    }

    /**
     * Begin a new conversation.
     *
     * This is what "Clear History" does, and it is deliberately NOT a delete.
     * The assistant's activity trail lives in `system_audit_logs` (written by
     * PaymentAssistantService::audit()) and every row there is stamped with the
     * conversation_id that was live when it was written. Rotating the id here
     * leaves all of that untouched and still attributable to the conversation
     * it belonged to, while the client's next request starts a clean thread.
     *
     * The chosen company is kept — clearing the conversation is not the same as
     * changing who you are paying, and making the client re-pick would be a
     * worse experience than the button promises. The invoice in view is dropped,
     * because that IS part of the visible conversation.
     *
     * Safe to call when the column is not yet migrated in: ensureConversation()
     * below is only ever reached through the same guarded write.
     */
    public function startConversation(): void
    {
        $this->update([
            'conversation_id'         => (string) Str::uuid(),
            'conversation_started_at' => now(),
            'invoice_id'              => null,
            'state'                   => $this->company_id
                ? self::WAITING_FOR_INVOICE
                : self::COMPANY_SELECTION,
        ]);
    }

    /** Stamp a conversation id on a session that predates this column. */
    public function ensureConversation(): void
    {
        if (!$this->conversation_id) {
            $this->update([
                'conversation_id'         => (string) Str::uuid(),
                'conversation_started_at' => $this->conversation_started_at ?? now(),
            ]);
        }
    }
}
