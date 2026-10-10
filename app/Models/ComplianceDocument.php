<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Stub model — filled in during Phase 2 (upload/review/versioning).
class ComplianceDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'project_id', 'compliance_case_id', 'compliance_requirement_id',
        'linked_attachment_id', 'uploaded_by_admin_id', 'uploaded_by_user_id',
        'original_name', 'file_name', 'file_path', 'file_type', 'file_size',
        'status', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class, 'compliance_case_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'compliance_requirement_id');
    }

    public function linkedAttachment(): BelongsTo
    {
        return $this->belongsTo(ProjectAttachment::class, 'linked_attachment_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ComplianceDocumentVersion::class)->orderByDesc('version_number');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ComplianceReview::class)->orderByDesc('created_at');
    }

    public function uploadedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'uploaded_by_admin_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
