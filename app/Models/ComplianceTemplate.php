<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'description', 'is_default', 'is_active',
        'created_by_admin_id', 'created_by_user_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ComplianceTemplateRequirement::class, 'template_id')->orderBy('sort_order');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
