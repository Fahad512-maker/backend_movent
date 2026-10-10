<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSellerAssignment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'project_id', 'old_seller_id', 'new_seller_id',
        'assigned_by', 'assigned_by_admin_id', 'reason',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function oldSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_seller_id');
    }

    public function newSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_seller_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assignedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'assigned_by_admin_id');
    }
}
