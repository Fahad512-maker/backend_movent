<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'lead_id', 'account_manager', 'user_id', 'name',
        'email', 'phone', 'company_name', 'address', 'country', 'portal_access', 'status', 'notes',
        'created_by', 'created_by_admin_id',
    ];

    protected $casts = ['portal_access' => 'boolean'];

    // Always present in JSON (as `creator`), but only ever populated when the
    // caller eager-loaded createdBy/createdByAdmin — see getCreatorAttribute().
    protected $appends = ['creator'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function aiServiceBatches(): HasMany
    {
        return $this->hasMany(ClientAiServiceBatch::class);
    }

    // Dual-actor "who created this" pair — same pattern as Lead/Project/
    // Invoice (see 2026_09_01_100000_add_created_by_admin_id_to_leads_table.php).
    // withTrashed() on createdBy: a since-removed staff member is still who
    // created this client.
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    // Only populated when the caller eager-loaded createdBy/createdByAdmin
    // (see Api\Admin\ClientController::show() / Api\User\ClientController::
    // show()) — deliberately does NOT lazy-load on its own, so a listing
    // endpoint that didn't ask for this doesn't pay an N+1 query per row.
    public function getCreatorAttribute(): ?array
    {
        if ($this->relationLoaded('createdBy') && $this->createdBy) {
            return ['id' => $this->createdBy->id, 'name' => $this->createdBy->name, 'type' => 'user'];
        }
        if ($this->relationLoaded('createdByAdmin') && $this->createdByAdmin) {
            return ['id' => $this->createdByAdmin->id, 'name' => $this->createdByAdmin->name, 'type' => 'admin'];
        }
        return null;
    }
}
