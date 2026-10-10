<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceRequirement extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'compliance_case_id', 'template_requirement_id',
        'source', 'name', 'description', 'category', 'is_mandatory', 'status',
        'requires_expiry', 'expires_at', 'sort_order',
        'added_by_admin_id', 'added_by_user_id',
    ];

    protected $casts = [
        'is_mandatory'    => 'boolean',
        'requires_expiry' => 'boolean',
        'expires_at'      => 'date',
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

    public function templateRequirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceTemplateRequirement::class, 'template_requirement_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ComplianceDocument::class, 'compliance_requirement_id');
    }

    public function addedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'added_by_admin_id');
    }

    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
