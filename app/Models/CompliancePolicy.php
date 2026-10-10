<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompliancePolicy extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'created_by', 'approved_by', 'title',
        'category', 'content', 'version', 'status', 'effective_date', 'review_date', 'file_path',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'review_date'    => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PolicyAssignment::class, 'policy_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComplianceItem::class, 'policy_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ComplianceViolation::class, 'policy_id');
    }
}
