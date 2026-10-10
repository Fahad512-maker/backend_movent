<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One generated batch of up to 10 AI-suggested services for a Client's ONE
 * project — see App\Services\ClientAiServiceBatchService. Exactly one
 * 'active' row per (client_id, project_id) pair at a time — a client with
 * two projects in different categories has two independent active batches,
 * never one shared between them; project_id = null is its own distinct pair
 * (a client/project with no project context yet). A regeneration flips the
 * old row for that SAME project to 'completed' (rotated_at records when)
 * rather than deleting it, so the history stays queryable. batch_number is
 * sequential per (client, project), starting at 1.
 */
class ClientAiServiceBatch extends Model
{
    protected $fillable = [
        'company_id', 'client_id', 'project_id', 'source_invoice_id',
        'status', 'generated_via', 'batch_number', 'rotated_at',
    ];

    protected $casts = [
        'rotated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // The project this batch was generated for/around — audit metadata; the
    // actual content is driven by App\Services\ClientServiceRecommendationService
    // at generation time, not re-derived from this relation. Nullable: a
    // batch can be generated before the client has any project yet.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientAiServiceBatchItem::class, 'batch_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // Scopes to the SAME (client, project) pair a batch is generated for —
    // never client_id alone. null $projectId is its own distinct scope (a
    // client/project with no project context yet), not a wildcard match.
    public function scopeForProject(Builder $query, ?int $projectId): Builder
    {
        return $projectId ? $query->where('project_id', $projectId) : $query->whereNull('project_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    // Derived, not stored — always consistent with the items' own 'taken'
    // status rather than a denormalized counter that could drift.
    public function purchasedCount(): int
    {
        return $this->items()->where('status', 'taken')->count();
    }
}
