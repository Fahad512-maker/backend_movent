<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceTemplateRequirement extends Model
{
    protected $fillable = [
        'company_id', 'template_id', 'name', 'description', 'category',
        'is_mandatory', 'requires_expiry', 'default_validity_days', 'sort_order',
    ];

    protected $casts = [
        'is_mandatory'    => 'boolean',
        'requires_expiry' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ComplianceTemplate::class, 'template_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
