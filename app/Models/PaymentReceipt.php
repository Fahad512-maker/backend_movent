<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated receipt for one confirmed payment.
 *
 * The file lives in the existing document architecture — see the `document`
 * relation — and this row is the index that ties it to the company, client,
 * invoice and payment it belongs to. Every authorization check in the receipt
 * feature is made of those four columns.
 *
 * Carries no money of its own on purpose: amounts, currency, status, method and
 * gateway reference are read live from `invoice` and `payment` so a listing can
 * never disagree with the payments table.
 */
class PaymentReceipt extends Model
{
    protected $fillable = [
        'company_id', 'client_id', 'invoice_id', 'payment_id',
        'document_id', 'file_path', 'file_name', 'receipt_number',
    ];

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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
