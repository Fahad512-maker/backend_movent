<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Stub model — filled in during Phase 2.
class ComplianceReview extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'compliance_case_id', 'compliance_requirement_id',
        'compliance_document_id', 'action', 'reason', 'old_status', 'new_status',
        'performed_by_admin_id', 'performed_by_user_id',
    ];

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class, 'compliance_case_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'compliance_requirement_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocument::class, 'compliance_document_id');
    }

    public function performedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'performed_by_admin_id');
    }

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
