<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'created_by', 'assigned_to', 'title',
        'category', 'description', 'likelihood', 'impact', 'risk_score',
        'risk_level', 'mitigation_plan', 'status', 'review_date',
    ];

    protected $casts = [
        'risk_score'  => 'integer',
        'review_date' => 'date',
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

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
