<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One suggested service within a ClientAiServiceBatch. status progresses
 * available -> requested (client clicked "Request Service" on the
 * dashboard) -> taken (a linked invoice — invoices.ai_service_batch_item_id
 * — was paid or partially paid). 'requested' is NOT a prerequisite for
 * 'taken': an Admin can link an invoice to an item the client never
 * clicked on. See App\Services\ClientAiServiceBatchService.
 */
class ClientAiServiceBatchItem extends Model
{
    protected $fillable = [
        'batch_id', 'company_service_id', 'key', 'name', 'category', 'summary', 'timeline',
        'status', 'requested_at', 'requested_notes', 'taken_at', 'taken_by_invoice_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'taken_at'     => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ClientAiServiceBatch::class, 'batch_id');
    }

    // Set when this suggestion was ranked from the company's own real
    // catalogue (see ClientServiceRecommendationService::rankCatalog()) —
    // null for a purely AI-generated suggestion with no backing catalogue
    // row. Lets a later batch recognise "this exact real service was already
    // purchased" by id, not just by name.
    public function companyService(): BelongsTo
    {
        return $this->belongsTo(CompanyService::class);
    }

    public function takenByInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'taken_by_invoice_id');
    }

    // Invoices explicitly linked to this suggested service via
    // invoices.ai_service_batch_item_id — normally just the one that
    // eventually pays and flips status to 'taken', kept as hasMany only
    // because nothing stops an Admin creating more than one before either
    // is paid.
    public function linkedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'ai_service_batch_item_id');
    }
}
