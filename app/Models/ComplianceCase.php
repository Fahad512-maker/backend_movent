<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceCase extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'client_id', 'status', 'template_id',
        'compliance_officer_id', 'officer_assigned_at',
        'officer_assigned_by_admin_id', 'officer_assigned_by_user_id',
        'started_review_at', 'compliant_at',
        'on_hold_at', 'on_hold_reason', 'rejected_at', 'rejected_reason',
    ];

    protected $casts = [
        'officer_assigned_at' => 'datetime',
        'started_review_at'   => 'datetime',
        'compliant_at'        => 'datetime',
        'on_hold_at'          => 'datetime',
        'rejected_at'         => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ComplianceTemplate::class, 'template_id');
    }

    public function complianceOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compliance_officer_id');
    }

    public function officerAssignedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'officer_assigned_by_admin_id');
    }

    public function officerAssignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_assigned_by_user_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ComplianceRequirement::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ComplianceDocument::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ComplianceChecklistItem::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ComplianceReview::class)->orderByDesc('created_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ComplianceComment::class)->orderByDesc('created_at');
    }
}
