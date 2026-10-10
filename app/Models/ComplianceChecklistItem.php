<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Stub model — filled in during Phase 2.
class ComplianceChecklistItem extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'compliance_case_id', 'compliance_requirement_id',
        'label', 'description', 'is_checked',
        'checked_by_admin_id', 'checked_by_user_id', 'checked_at', 'sort_order',
    ];

    protected $casts = [
        'is_checked' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class, 'compliance_case_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'compliance_requirement_id');
    }

    public function checkedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'checked_by_admin_id');
    }

    public function checkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
