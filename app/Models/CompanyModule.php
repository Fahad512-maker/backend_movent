<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModule extends Model
{
    const CREATED_AT = null;

    protected $fillable = ['company_id', 'module_key', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
