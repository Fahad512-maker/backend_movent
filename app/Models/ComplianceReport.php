<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceReport extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'project_id', 'generated_by', 'title', 'report_type',
        'period_from', 'period_to', 'file_path',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
